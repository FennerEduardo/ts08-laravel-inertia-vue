<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\DomainValidationException;
use App\Runtime\Broker;
use App\Runtime\BrokerMessage;
use App\Runtime\Brokers;
use App\Runtime\CommandService;
use App\Runtime\Database;
use App\Runtime\InboxConsumer;
use App\Runtime\Messaging;
use App\Runtime\OutboxRelay;
use App\Runtime\SagaOrchestrator;
use App\Runtime\Schema;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Runtime integration tests (docs/RUNTIME-KERNEL.md, IT1-IT7). Requires DATABASE_URL and the broker variables. */
#[Group('integration')]
final class RuntimeIntegrationTest extends TestCase
{
    private const COMMAND = 'process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue';
    private const EVENT = 'ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted';
    private static InMemoryExporter $exporter;
    private string $databaseUrl;
    private string $schema;
    private Messaging $topology;
    private ?Broker $broker = null;

    public static function setUpBeforeClass(): void
    {
        self::$exporter = new InMemoryExporter();
        Sdk::builder()->setTracerProvider(new TracerProvider(new SimpleSpanProcessor(self::$exporter)))->buildAndRegisterGlobal();
    }

    protected function setUp(): void
    {
        $this->databaseUrl = (string) getenv('DATABASE_URL');
        if ($this->databaseUrl === '' || !Brokers::configured()) {
            self::fail('Integration tests need DATABASE_URL and the broker variables (see docs/RUNTIME-KERNEL.md).');
        }
        $uid = bin2hex(random_bytes(6));
        $this->schema = "it_{$uid}";
        $this->topology = Messaging::forPrefix("it-{$uid}");
        Schema::migrate($this->databaseUrl, $this->schema);
        self::$exporter->getStorage()->exchangeArray([]);
    }

    protected function tearDown(): void
    {
        $this->broker?->close();
        Database::connect($this->databaseUrl)->exec("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
    }

    /** The broker with this test's topology declared. */
    private function broker(): Broker
    {
        if ($this->broker === null) {
            $this->broker = Brokers::connect();
            $this->broker->declare($this->topology);
        }
        return $this->broker;
    }

    /**
     * Receives and acknowledges messages until $expected arrived and the source stays quiet for 0.5 s (10 s at most).
     *
     * @return list<BrokerMessage>
     */
    private function receive(string $source, int $expected): array
    {
        $subscription = $this->broker()->subscribe($source, $this->topology);
        $out = [];
        $deadline = microtime(true) + 10;
        $quietSince = microtime(true);
        while (microtime(true) < $deadline && (count($out) < $expected || microtime(true) - $quietSince < 0.5)) {
            $delivery = $subscription->poll();
            if ($delivery === null) {
                usleep(50_000);
                continue;
            }
            $out[] = $delivery->message;
            $delivery->ack();
            $quietSince = microtime(true);
        }
        $subscription->close();
        return $out;
    }

    private function countRows(string $table, string $where = 'true', array $params = []): int
    {
        return (int) Database::rows(Database::connect($this->databaseUrl), "SELECT count(*) AS n FROM {$this->schema}.{$table} WHERE {$where}", $params)[0]['n'];
    }

    /** Runs workers in parallel processes (PHP has no threads) and returns their decoded JSON outputs. */
    private function runWorkers(string $mode, array $argsList): array
    {
        $running = [];
        foreach ($argsList as $args) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $mode, json_encode($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $running[] = [$process, $pipes];
        }
        $outputs = [];
        foreach ($running as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), "worker failed: {$stderr}");
            $outputs[] = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        }
        return $outputs;
    }


    public function testIT1AtomicWriteAndRollback(): void
    {
        $service = new CommandService($this->databaseUrl, $this->schema);
        $result = $service->handle('t1', 'agg-1', self::COMMAND);
        self::assertSame(['created', self::EVENT, 1], [$result['status'], $result['eventType'], $result['version']]);
        self::assertSame(1, $service->load('t1', 'agg-1')['version']);
        self::assertSame(1, $this->countRows('ghk_outbox', 'aggregate_id = ?', ['agg-1']));
        try {
            $service->handle('t1', 'agg-2', 'no_such_command', [], 'k-fail');
            self::fail('Expected a domain error');
        } catch (DomainValidationException $e) {
            self::assertStringContainsString('Unknown command', $e->getMessage());
        }
        self::assertNull($service->load('t1', 'agg-2'));
        self::assertSame(0, $this->countRows('ghk_outbox', 'aggregate_id = ?', ['agg-2']));
        self::assertSame(0, $this->countRows('ghk_idempotency', 'key = ?', ['k-fail']));
    }

    public function testIT2ConcurrentIdempotentRequests(): void
    {
        $args = ['schema' => $this->schema, 'tenantId' => 't1', 'aggregateId' => 'agg-1', 'command' => self::COMMAND, 'key' => 'key-1'];
        $results = $this->runWorkers('handle', array_fill(0, 5, $args));
        self::assertSame(1, $this->countRows('ghk_outbox'));
        self::assertSame(1, (new CommandService($this->databaseUrl, $this->schema))->load('t1', 'agg-1')['version']);
        self::assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'created'));
        foreach ($results as $r) {
            self::assertSame([self::EVENT, 1], [$r['eventType'], $r['version']]);
        }
    }

    public function testIT3ConcurrentRelaysPublishExactlyOnce(): void
    {
        $service = new CommandService($this->databaseUrl, $this->schema);
        for ($i = 0; $i < 20; $i++) {
            $service->handle('t1', "agg-{$i}", self::COMMAND);
        }
        $this->broker();
        $outputs = $this->runWorkers('relay', [
            ['schema' => $this->schema, 'exchange' => $this->topology->exchange, 'worker' => 'relay-a'],
            ['schema' => $this->schema, 'exchange' => $this->topology->exchange, 'worker' => 'relay-b'],
        ]);
        self::assertSame(20, array_sum(array_column($outputs, 'published')));
        self::assertSame(0, $this->countRows('ghk_outbox', 'published_at IS NULL'));
        $received = $this->receive($this->topology->queue, 20);
        self::assertCount(20, $received);
        self::assertCount(20, array_unique(array_map(fn (BrokerMessage $m) => $m->messageId, $received)));
    }

    public function testIT4TenantIsolation(): void
    {
        $service = new CommandService($this->databaseUrl, $this->schema);
        $service->handle('tenant-a', 'shared-id', self::COMMAND);
        self::assertNull($service->load('tenant-b', 'shared-id'));
        $service->handle('tenant-b', 'shared-id', self::COMMAND);
        self::assertSame(1, $service->load('tenant-a', 'shared-id')['version']);
        self::assertSame(1, $service->load('tenant-b', 'shared-id')['version']);
        self::assertSame(1, $this->countRows('ghk_outbox', 'tenant_id = ?', ['tenant-a']));
    }

    public function testIT5SagaCompensatesInReverseOrder(): void
    {
        $saga = new SagaOrchestrator($this->databaseUrl, $this->schema);
        $log = [];
        $step = function (string $name, bool $fail = false) use (&$log): array {
            return [
                'name' => $name,
                'action' => function () use ($name, $fail, &$log): void {
                    if ($fail) {
                        throw new \RuntimeException("{$name} failed");
                    }
                    $log[] = "do:{$name}";
                },
                'compensate' => function () use ($name, &$log): void {
                    $log[] = "undo:{$name}";
                },
            ];
        };
        self::assertSame('COMPENSATED', $saga->run('saga-1', 't1', [$step('reserve'), $step('charge'), $step('ship', true)]));
        self::assertSame(['do:reserve', 'do:charge', 'undo:charge', 'undo:reserve'], $log);
        self::assertSame(['status' => 'COMPENSATED', 'completedSteps' => []], $saga->status('saga-1'));
        self::assertSame('COMPLETED', $saga->run('saga-2', 't1', [$step('reserve'), $step('charge')]));
    }

    public function testIT6InboxDeduplicatesAndDeadLetters(): void
    {
        $broker = $this->broker();
        $publisher = $broker->publisher($this->topology->exchange);
        foreach ([['m-1', '{"ok": true}'], ['m-1', '{"ok": true}'], ['m-poison', '{"poison": true}']] as [$id, $body]) {
            $publisher->publish(new BrokerMessage($id, 'Test', 't1', null, $body));
        }
        $publisher->close();
        $handled = [];
        $subscription = $broker->subscribe($this->topology->queue, $this->topology);
        (new InboxConsumer($this->databaseUrl, $subscription, 'it-consumer', function (array $event, array $meta) use (&$handled): void {
            if (!empty($event['poison'])) {
                throw new \RuntimeException('cannot process');
            }
            $handled[] = $meta['messageId'];
        }, $this->schema))->drain(1.0);
        $subscription->close();
        self::assertSame(['m-1'], $handled);
        self::assertSame(1, $this->countRows('ghk_inbox', 'consumer = ?', ['it-consumer']));
        self::assertSame(['m-poison'], array_map(fn (BrokerMessage $m) => $m->messageId, $this->receive($this->topology->dlq, 1)));
    }

    public function testIT7TraceContextPropagation(): void
    {
        $service = new CommandService($this->databaseUrl, $this->schema);
        $service->handle('t1', 'agg-1', self::COMMAND, [], null, '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        $row = Database::rows(Database::connect($this->databaseUrl), "SELECT traceparent FROM {$this->schema}.ghk_outbox")[0];
        self::assertStringContainsString('4bf92f3577b34da6a3ce929d0e0e4736', (string) $row['traceparent']);
        $broker = $this->broker();
        $publisher = $broker->publisher($this->topology->exchange);
        self::assertSame(1, (new OutboxRelay($this->databaseUrl, $publisher, $this->schema))->publishBatch('relay', 10));
        $publisher->close();
        $received = 0;
        $subscription = $broker->subscribe($this->topology->queue, $this->topology);
        (new InboxConsumer($this->databaseUrl, $subscription, 'trace-consumer', function () use (&$received): void {
            $received++;
        }, $this->schema))->drain(1.0);
        $subscription->close();
        self::assertSame(1, $received);

        $byKind = [];
        foreach (self::$exporter->getSpans() as $span) {
            $byKind[$span->getKind()] = $span;
        }
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $byKind[SpanKind::KIND_INTERNAL]->getTraceId());
        self::assertSame('00f067aa0ba902b7', $byKind[SpanKind::KIND_INTERNAL]->getParentSpanId());
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $byKind[SpanKind::KIND_PRODUCER]->getTraceId());
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $byKind[SpanKind::KIND_CONSUMER]->getTraceId());
        self::assertSame($byKind[SpanKind::KIND_PRODUCER]->getSpanId(), $byKind[SpanKind::KIND_CONSUMER]->getParentSpanId());
    }
}

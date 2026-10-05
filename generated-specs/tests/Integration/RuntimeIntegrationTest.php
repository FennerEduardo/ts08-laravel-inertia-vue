<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\DomainValidationException;
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
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Runtime integration tests (docs/RUNTIME-KERNEL.md, IT1-IT7). Requires DATABASE_URL and AMQP_URL. */
#[Group('integration')]
final class RuntimeIntegrationTest extends TestCase
{
    private const COMMAND = 'process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue';
    private const EVENT = 'ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted';
    private static InMemoryExporter $exporter;
    private string $databaseUrl;
    private string $amqpUrl;
    private string $schema;
    private Messaging $topology;

    public static function setUpBeforeClass(): void
    {
        self::$exporter = new InMemoryExporter();
        Sdk::builder()->setTracerProvider(new TracerProvider(new SimpleSpanProcessor(self::$exporter)))->buildAndRegisterGlobal();
    }

    protected function setUp(): void
    {
        $this->databaseUrl = (string) getenv('DATABASE_URL');
        $this->amqpUrl = (string) getenv('AMQP_URL');
        if ($this->databaseUrl === '' || $this->amqpUrl === '') {
            self::fail('Integration tests need DATABASE_URL and AMQP_URL (see docs/RUNTIME-KERNEL.md).');
        }
        $uid = bin2hex(random_bytes(6));
        $this->schema = "it_{$uid}";
        $this->topology = Messaging::forPrefix("it-{$uid}");
        Schema::migrate($this->databaseUrl, $this->schema);
        self::$exporter->getStorage()->exchangeArray([]);
    }

    protected function tearDown(): void
    {
        Database::connect($this->databaseUrl)->exec("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
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

    private static function waitFor(callable $check): void
    {
        $deadline = microtime(true) + 10;
        while (!$check()) {
            if (microtime(true) > $deadline) {
                self::fail('Timed out waiting for condition');
            }
            usleep(50_000);
        }
    }

    /** @return list<AMQPMessage> */
    private static function drainQueue($channel, string $queue): array
    {
        $messages = [];
        while (($message = $channel->basic_get($queue, true)) !== null) {
            $messages[] = $message;
        }
        return $messages;
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
        $connection = Messaging::connect($this->amqpUrl);
        $channel = $connection->channel();
        $this->topology->declare($channel);
        $outputs = $this->runWorkers('relay', [
            ['schema' => $this->schema, 'exchange' => $this->topology->exchange, 'worker' => 'relay-a'],
            ['schema' => $this->schema, 'exchange' => $this->topology->exchange, 'worker' => 'relay-b'],
        ]);
        self::assertSame(20, array_sum(array_column($outputs, 'published')));
        self::assertSame(0, $this->countRows('ghk_outbox', 'published_at IS NULL'));
        self::waitFor(fn () => Messaging::messageCount($channel, $this->topology->queue) === 20);
        $ids = array_map(fn (AMQPMessage $m) => $m->get('message_id'), self::drainQueue($channel, $this->topology->queue));
        self::assertCount(20, array_unique($ids));
        $connection->close();
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
        $connection = Messaging::connect($this->amqpUrl);
        $channel = $connection->channel();
        $this->topology->declare($channel);
        $handled = [];
        $consumer = new InboxConsumer($this->databaseUrl, $channel, $this->topology->queue, 'it-consumer', function (array $event, array $meta) use (&$handled): void {
            if (!empty($event['poison'])) {
                throw new \RuntimeException('cannot process');
            }
            $handled[] = $meta['messageId'];
        }, $this->schema);
        foreach ([['m-1', '{"ok": true}'], ['m-1', '{"ok": true}'], ['m-poison', '{"poison": true}']] as [$id, $body]) {
            $channel->basic_publish(new AMQPMessage($body, ['message_id' => $id, 'application_headers' => new AMQPTable(['tenant_id' => 't1'])]), $this->topology->exchange, 'Test');
        }
        $consumer->drain(1.0);
        self::assertSame(['m-1'], $handled);
        self::assertSame(1, $this->countRows('ghk_inbox', 'consumer = ?', ['it-consumer']));
        self::waitFor(fn () => Messaging::messageCount($channel, $this->topology->dlq) === 1);
        self::assertSame(['m-poison'], array_map(fn (AMQPMessage $m) => $m->get('message_id'), self::drainQueue($channel, $this->topology->dlq)));
        $connection->close();
    }

    public function testIT7TraceContextPropagation(): void
    {
        $service = new CommandService($this->databaseUrl, $this->schema);
        $service->handle('t1', 'agg-1', self::COMMAND, [], null, '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        $row = Database::rows(Database::connect($this->databaseUrl), "SELECT traceparent FROM {$this->schema}.ghk_outbox")[0];
        self::assertStringContainsString('4bf92f3577b34da6a3ce929d0e0e4736', (string) $row['traceparent']);
        $connection = Messaging::connect($this->amqpUrl);
        $channel = $connection->channel();
        $this->topology->declare($channel);
        self::assertSame(1, (new OutboxRelay($this->databaseUrl, $channel, $this->topology->exchange, $this->schema))->publishBatch('relay', 10));
        $received = 0;
        (new InboxConsumer($this->databaseUrl, $connection->channel(), $this->topology->queue, 'trace-consumer', function () use (&$received): void {
            $received++;
        }, $this->schema))->drain(1.0);
        self::assertSame(1, $received);
        $connection->close();

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

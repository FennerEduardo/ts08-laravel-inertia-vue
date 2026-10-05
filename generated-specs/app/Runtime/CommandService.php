<?php

declare(strict_types=1);

namespace App\Runtime;

use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregate;
use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\CheckoutAutenticadoConLaravelSanctumEInertiaVueCommand;
use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\CheckoutAutenticadoConLaravelSanctumEInertiaVueState;
use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\DomainValidationException;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PDO;

/**
 * Executes a command in one transaction: idempotency claim, aggregate load (FOR UPDATE), domain
 * logic, aggregate save and outbox insert. A domain error rolls back everything.
 */
final class CommandService
{
    private const AGGREGATE_TYPE = 'CheckoutAutenticadoConLaravelSanctumEInertiaVue';
    private const COMMANDS = [
        'process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue' => 'processCheckoutAutenticadoConLaravelSanctumEInertiaVue',
    ];

    private readonly string $s;

    public function __construct(private readonly string $databaseUrl, string $schema = 'public')
    {
        $this->s = Schema::name($schema);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, aggregateId: string, eventType: ?string, version: ?int}
     */
    public function handle(string $tenantId, string $aggregateId, string $command, array $payload = [], ?string $idempotencyKey = null, ?string $traceparent = null): array
    {
        if ($tenantId === '') {
            throw new DomainValidationException('tenantId is required');
        }
        $parent = Telemetry::contextFrom($traceparent);
        $span = Telemetry::tracer()->spanBuilder("CheckoutAutenticadoConLaravelSanctumEInertiaVue.{$command}")->setParent($parent)->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('tenant.id', $tenantId)->setAttribute('aggregate.id', $aggregateId)->startSpan();
        $pdo = Database::connect($this->databaseUrl);
        try {
            $pdo->beginTransaction();
            $result = $this->execute($pdo, $tenantId, $aggregateId, $command, $payload, $idempotencyKey, Telemetry::traceparentOf($span, $parent));
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, aggregateId: string, eventType: ?string, version: ?int}
     */
    private function execute(PDO $pdo, string $tenantId, string $aggregateId, string $command, array $payload, ?string $key, ?string $traceparent): array
    {
        $s = $this->s;
        if ($key !== null) {
            if (Database::exec($pdo, "INSERT INTO {$s}.ghk_idempotency (tenant_id, key, status) VALUES (?, ?, 'PROCESSING') ON CONFLICT DO NOTHING", [$tenantId, $key]) === 0) {
                $row = Database::rows($pdo, "SELECT status, response FROM {$s}.ghk_idempotency WHERE tenant_id = ? AND key = ?", [$tenantId, $key])[0] ?? null;
                if ($row !== null && $row['status'] === 'COMPLETED') {
                    return ['status' => 'replayed'] + json_decode((string) $row['response'], true);
                }
                return ['status' => 'in-progress', 'aggregateId' => $aggregateId, 'eventType' => null, 'version' => null];
            }
        }
        $row = Database::rows($pdo, "SELECT state, version FROM {$s}.ghk_aggregates WHERE tenant_id = ? AND aggregate_type = ? AND id = ? FOR UPDATE",
            [$tenantId, self::AGGREGATE_TYPE, $aggregateId])[0] ?? null;
        $aggregate = $row !== null
            ? CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregate::restore($aggregateId, CheckoutAutenticadoConLaravelSanctumEInertiaVueState::from((string) $row['state']), (int) $row['version'])
            : new CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregate($aggregateId);
        $method = self::COMMANDS[$command] ?? throw new DomainValidationException("Unknown command {$command}");
        $event = $aggregate->{$method}(new CheckoutAutenticadoConLaravelSanctumEInertiaVueCommand($aggregateId, $payload));
        Database::exec($pdo, "INSERT INTO {$s}.ghk_aggregates (tenant_id, aggregate_type, id, state, version) VALUES (?, ?, ?, ?, ?) "
            . 'ON CONFLICT (tenant_id, aggregate_type, id) DO UPDATE SET state = EXCLUDED.state, version = EXCLUDED.version, updated_at = now()',
            [$tenantId, self::AGGREGATE_TYPE, $aggregateId, $aggregate->state()->value, $aggregate->version()]);
        Database::exec($pdo, "INSERT INTO {$s}.ghk_outbox (id, tenant_id, aggregate_id, event_type, payload, traceparent) VALUES (?, ?, ?, ?, ?, ?)",
            [self::uuid(), $tenantId, $aggregateId, $event->type->value, json_encode($event, JSON_THROW_ON_ERROR), $traceparent]);
        $result = ['status' => 'created', 'aggregateId' => $aggregateId, 'eventType' => $event->type->value, 'version' => $event->version];
        if ($key !== null) {
            Database::exec($pdo, "UPDATE {$s}.ghk_idempotency SET status = 'COMPLETED', response = ? WHERE tenant_id = ? AND key = ?",
                [json_encode($result, JSON_THROW_ON_ERROR), $tenantId, $key]);
        }
        return $result;
    }

    /** @return array{state: string, version: int}|null The aggregate as the tenant sees it (null for other tenants'). */
    public function load(string $tenantId, string $aggregateId): ?array
    {
        $row = Database::rows(Database::connect($this->databaseUrl),
            "SELECT state, version FROM {$this->s}.ghk_aggregates WHERE tenant_id = ? AND aggregate_type = ? AND id = ?",
            [$tenantId, self::AGGREGATE_TYPE, $aggregateId])[0] ?? null;
        return $row === null ? null : ['state' => (string) $row['state'], 'version' => (int) $row['version']];
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

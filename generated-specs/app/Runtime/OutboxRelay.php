<?php

declare(strict_types=1);

namespace App\Runtime;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

/**
 * Publishes pending outbox rows. Rows are claimed with FOR UPDATE SKIP LOCKED and a lease, so
 * concurrent relays never publish the same row; the publisher returns only once the broker has
 * accepted the message, so a row is marked published only after delivery. Use one relay per process.
 */
final class OutboxRelay
{
    private readonly string $s;

    public function __construct(
        private readonly string $databaseUrl,
        private readonly EventPublisher $publisher,
        string $schema = 'public',
        private readonly int $leaseSeconds = 30,
        private readonly int $maxAttempts = 5,
    ) {
        $this->s = Schema::name($schema);
    }

    public function publishBatch(string $workerId, int $limit = 50): int
    {
        $s = $this->s;
        $pdo = Database::connect($this->databaseUrl);
        $rows = Database::rows($pdo,
            "UPDATE {$s}.ghk_outbox SET claimed_by = ?, claimed_until = now() + make_interval(secs => ?), attempts = attempts + 1 "
            . "WHERE id IN (SELECT id FROM {$s}.ghk_outbox WHERE published_at IS NULL AND failed_at IS NULL AND (claimed_until IS NULL OR claimed_until < now()) "
            . 'ORDER BY created_at LIMIT ? FOR UPDATE SKIP LOCKED) RETURNING id, tenant_id, event_type, payload, traceparent',
            [$workerId, $this->leaseSeconds, $limit]);
        $published = 0;
        foreach ($rows as $row) {
            $parent = Telemetry::contextFrom($row['traceparent']);
            $destination = $this->publisher->destination();
            $span = Telemetry::tracer()->spanBuilder("{$destination} publish")->setParent($parent)->setSpanKind(SpanKind::KIND_PRODUCER)
                ->setAttribute('messaging.system', $this->publisher->system())->setAttribute('messaging.destination.name', $destination)
                ->setAttribute('messaging.message.id', $row['id'])->setAttribute('tenant.id', $row['tenant_id'])->startSpan();
            try {
                $this->publisher->publish(new BrokerMessage((string) $row['id'], $row['event_type'], $row['tenant_id'],
                    Telemetry::traceparentOf($span, $parent), (string) $row['payload']));
                Database::exec($pdo, "UPDATE {$s}.ghk_outbox SET published_at = now(), claimed_until = NULL WHERE id = ? AND claimed_by = ?", [$row['id'], $workerId]);
                $published++;
            } catch (\Throwable $e) {
                $span->recordException($e);
                $span->setStatus(StatusCode::STATUS_ERROR);
                Database::exec($pdo, "UPDATE {$s}.ghk_outbox SET claimed_until = NULL, last_error = ?, failed_at = CASE WHEN attempts >= ? THEN now() ELSE NULL END WHERE id = ?",
                    [$e->getMessage(), $this->maxAttempts, $row['id']]);
            } finally {
                $span->end();
            }
        }
        return $published;
    }
}

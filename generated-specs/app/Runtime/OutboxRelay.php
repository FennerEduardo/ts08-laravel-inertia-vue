<?php

declare(strict_types=1);

namespace App\Runtime;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Publishes pending outbox rows. Rows are claimed with FOR UPDATE SKIP LOCKED and a lease, so
 * concurrent relays never publish the same row; publisher confirms guarantee delivery to the broker
 * before a row is marked published. Use one relay (and channel) per process.
 */
final class OutboxRelay
{
    private readonly string $s;
    private bool $nacked = false;

    public function __construct(
        private readonly string $databaseUrl,
        private readonly AMQPChannel $channel,
        private readonly string $exchange,
        string $schema = 'public',
        private readonly int $leaseSeconds = 30,
        private readonly int $maxAttempts = 5,
    ) {
        $this->s = Schema::name($schema);
        $this->channel->confirm_select();
        $this->channel->set_nack_handler(function (): void {
            $this->nacked = true;
        });
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
            $span = Telemetry::tracer()->spanBuilder("{$this->exchange} publish")->setParent($parent)->setSpanKind(SpanKind::KIND_PRODUCER)
                ->setAttribute('messaging.system', 'rabbitmq')->setAttribute('messaging.destination.name', $this->exchange)
                ->setAttribute('messaging.message.id', $row['id'])->setAttribute('tenant.id', $row['tenant_id'])->startSpan();
            try {
                $headers = ['tenant_id' => $row['tenant_id']];
                $traceparent = Telemetry::traceparentOf($span, $parent);
                if ($traceparent !== null) {
                    $headers['traceparent'] = $traceparent;
                }
                $this->nacked = false;
                $this->channel->basic_publish(new AMQPMessage((string) $row['payload'], [
                    'message_id' => $row['id'], 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'content_type' => 'application/json', 'type' => $row['event_type'], 'application_headers' => new AMQPTable($headers),
                ]), $this->exchange, (string) $row['event_type']);
                $this->channel->wait_for_pending_acks(10);
                if ($this->nacked) {
                    throw new \RuntimeException("Broker rejected {$row['id']}");
                }
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

<?php

declare(strict_types=1);

namespace App\Runtime;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PDO;

/**
 * Idempotent consumer: the message id is recorded in ghk_inbox in the same transaction as the
 * handler, so redeliveries are acknowledged without running the handler twice. A handler error
 * dead-letters the message to the DLQ.
 */
final class InboxConsumer
{
    private readonly string $s;

    /** @param \Closure(array<string, mixed>, array{messageId: string, tenantId: ?string, eventType: ?string, pdo: PDO}): void $handler */
    public function __construct(
        private readonly string $databaseUrl,
        private readonly EventSubscription $subscription,
        private readonly string $consumerName,
        private readonly \Closure $handler,
        string $schema = 'public',
    ) {
        $this->s = Schema::name($schema);
    }

    /** Processes messages until the queue stays empty for $idleSeconds; returns how many were processed. */
    public function drain(float $idleSeconds = 1.0): int
    {
        $processed = 0;
        $idleSince = microtime(true);
        while (microtime(true) - $idleSince < $idleSeconds) {
            $delivery = $this->subscription->poll();
            if ($delivery === null) {
                usleep(50_000);
                continue;
            }
            $this->process($delivery);
            $processed++;
            $idleSince = microtime(true);
        }
        return $processed;
    }

    private function process(Delivery $delivery): void
    {
        $message = $delivery->message;
        $source = $this->subscription->source();
        $span = Telemetry::tracer()->spanBuilder("{$source} process")->setParent(Telemetry::contextFrom($message->traceparent))
            ->setSpanKind(SpanKind::KIND_CONSUMER)->setAttribute('messaging.system', $this->subscription->system())
            ->setAttribute('messaging.destination.name', $source)->setAttribute('messaging.message.id', $message->messageId)->startSpan();
        $pdo = Database::connect($this->databaseUrl);
        try {
            $pdo->beginTransaction();
            if (Database::exec($pdo, "INSERT INTO {$this->s}.ghk_inbox (consumer, message_id) VALUES (?, ?) ON CONFLICT DO NOTHING", [$this->consumerName, $message->messageId]) === 1) {
                ($this->handler)(json_decode($message->body, true, 512, JSON_THROW_ON_ERROR), [
                    'messageId' => $message->messageId,
                    'tenantId' => $message->tenantId,
                    'eventType' => $message->eventType,
                    'pdo' => $pdo,
                ]);
            }
            $pdo->commit();
            $delivery->ack();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR);
            $delivery->deadLetter();
        } finally {
            $span->end();
        }
    }
}

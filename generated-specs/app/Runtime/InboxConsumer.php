<?php

declare(strict_types=1);

namespace App\Runtime;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PDO;

/**
 * Idempotent consumer: the message id is recorded in ghk_inbox in the same transaction as the
 * handler, so redeliveries are acknowledged without running the handler twice. A handler error
 * rejects the message without requeue, so RabbitMQ dead-letters it to the DLQ.
 */
final class InboxConsumer
{
    private readonly string $s;

    /** @param \Closure(array<string, mixed>, array{messageId: string, tenantId: ?string, eventType: ?string, pdo: PDO}): void $handler */
    public function __construct(
        private readonly string $databaseUrl,
        private readonly AMQPChannel $channel,
        private readonly string $queue,
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
            $message = $this->channel->basic_get($this->queue);
            if ($message === null) {
                usleep(50_000);
                continue;
            }
            $this->process($message);
            $processed++;
            $idleSince = microtime(true);
        }
        return $processed;
    }

    private function process(AMQPMessage $message): void
    {
        $headers = $message->has('application_headers') ? $message->get('application_headers')->getNativeData() : [];
        $messageId = $message->has('message_id') ? (string) $message->get('message_id') : '';
        $span = Telemetry::tracer()->spanBuilder("{$this->queue} process")->setParent(Telemetry::contextFrom($headers['traceparent'] ?? null))
            ->setSpanKind(SpanKind::KIND_CONSUMER)->setAttribute('messaging.system', 'rabbitmq')
            ->setAttribute('messaging.destination.name', $this->queue)->setAttribute('messaging.message.id', $messageId)->startSpan();
        $pdo = Database::connect($this->databaseUrl);
        try {
            $pdo->beginTransaction();
            if (Database::exec($pdo, "INSERT INTO {$this->s}.ghk_inbox (consumer, message_id) VALUES (?, ?) ON CONFLICT DO NOTHING", [$this->consumerName, $messageId]) === 1) {
                ($this->handler)(json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR), [
                    'messageId' => $messageId,
                    'tenantId' => $headers['tenant_id'] ?? null,
                    'eventType' => $message->has('type') ? (string) $message->get('type') : null,
                    'pdo' => $pdo,
                ]);
            }
            $pdo->commit();
            $message->ack();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR);
            $message->reject(false);
        } finally {
            $span->end();
        }
    }
}

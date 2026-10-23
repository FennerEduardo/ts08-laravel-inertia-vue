<?php

declare(strict_types=1);

namespace App\Runtime;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/** RabbitMQ 0-9-1: topic exchange, publisher confirms, dead-lettering through the queue's DLX. */
final class RabbitMqBroker implements Broker
{
    public function __construct(private readonly AbstractConnection $connection)
    {
    }

    /** From an AMQP URL: amqp://user:password@host:5672/vhost, or amqps:// (TLS, port 5671: Amazon MQ, CloudAMQP). */
    public static function connect(string $amqpUrl): self
    {
        $url = parse_url($amqpUrl);
        $secure = ($url['scheme'] ?? 'amqp') === 'amqps';
        $config = new AMQPConnectionConfig();
        $config->setHost($url['host']);
        $config->setPort((int) ($url['port'] ?? ($secure ? 5671 : 5672)));
        $config->setUser(urldecode($url['user'] ?? 'guest'));
        $config->setPassword(urldecode($url['pass'] ?? 'guest'));
        $config->setVhost(isset($url['path']) && $url['path'] !== '/' && $url['path'] !== '' ? urldecode(substr($url['path'], 1)) : '/');
        if ($secure) {
            $config->setIsSecure(true);
            $config->setSslVerify(true);
            $config->setSslVerifyName(true);
        }
        return new self(AMQPConnectionFactory::create($config));
    }

    public function system(): string
    {
        return 'rabbitmq';
    }

    public function declare(Messaging $t): void
    {
        $channel = $this->connection->channel();
        $channel->exchange_declare($t->exchange, 'topic', false, true, false);
        $channel->exchange_declare($t->dlx, 'fanout', false, true, false);
        $channel->queue_declare($t->dlq, false, true, false, false);
        $channel->queue_bind($t->dlq, $t->dlx, '');
        $channel->queue_declare($t->queue, false, true, false, false, false, new AMQPTable(['x-dead-letter-exchange' => $t->dlx]));
        $channel->queue_bind($t->queue, $t->exchange, '#');
        $channel->close();
    }

    public function publisher(string $exchange): EventPublisher
    {
        $channel = $this->connection->channel();
        return new class ($channel, $exchange) implements EventPublisher {
            private bool $nacked = false;

            public function __construct(private readonly AMQPChannel $channel, private readonly string $exchange)
            {
                $this->channel->confirm_select();
                $this->channel->set_nack_handler(function (): void {
                    $this->nacked = true;
                });
            }

            public function system(): string
            {
                return 'rabbitmq';
            }

            public function destination(): string
            {
                return $this->exchange;
            }

            public function publish(BrokerMessage $m): void
            {
                $headers = array_filter(['tenant_id' => $m->tenantId, 'traceparent' => $m->traceparent], fn ($v) => $v !== null);
                $this->nacked = false;
                $this->channel->basic_publish(new AMQPMessage($m->body, [
                    'message_id' => $m->messageId, 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'content_type' => 'application/json', 'type' => (string) $m->eventType, 'application_headers' => new AMQPTable($headers),
                ]), $this->exchange, (string) $m->eventType);
                $this->channel->wait_for_pending_acks(10);
                if ($this->nacked) {
                    throw new \RuntimeException("Broker rejected {$m->messageId}");
                }
            }

            public function close(): void
            {
                $this->channel->close();
            }
        };
    }

    public function subscribe(string $queue, Messaging $topology): EventSubscription
    {
        $channel = $this->connection->channel();
        return new class ($channel, $queue) implements EventSubscription {
            public function __construct(private readonly AMQPChannel $channel, private readonly string $queue)
            {
            }

            public function system(): string
            {
                return 'rabbitmq';
            }

            public function source(): string
            {
                return $this->queue;
            }

            public function poll(): ?Delivery
            {
                $message = $this->channel->basic_get($this->queue);
                if ($message === null) {
                    return null;
                }
                $headers = $message->has('application_headers') ? $message->get('application_headers')->getNativeData() : [];
                $brokerMessage = new BrokerMessage(
                    $message->has('message_id') ? (string) $message->get('message_id') : '',
                    $message->has('type') ? (string) $message->get('type') : null,
                    isset($headers['tenant_id']) ? (string) $headers['tenant_id'] : null,
                    isset($headers['traceparent']) ? (string) $headers['traceparent'] : null,
                    $message->getBody(),
                );
                // Rejected without requeue: RabbitMQ dead-letters it through the queue's DLX.
                return new Delivery($brokerMessage, fn () => $message->ack(), fn () => $message->reject(false));
            }

            public function close(): void
            {
                $this->channel->close();
            }
        };
    }

    public function close(): void
    {
        $this->connection->close();
    }
}

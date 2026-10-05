<?php

declare(strict_types=1);

namespace App\Runtime;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

/** RabbitMQ connection and topology: topic exchange -> consumer queue dead-lettering to a fanout DLX -> DLQ. */
final class Messaging
{
    public const DEFAULT_PREFIX = 'checkout-autenticado-con-laravel-sanctum-e-inertia-vue';

    public function __construct(public readonly string $exchange, public readonly string $queue, public readonly string $dlx, public readonly string $dlq)
    {
    }

    public static function forPrefix(string $prefix = self::DEFAULT_PREFIX): self
    {
        return new self("{$prefix}.events", "{$prefix}.consumer", "{$prefix}.dlx", "{$prefix}.dlq");
    }

    /** From an AMQP URL: amqp://user:password@host:5672/vhost */
    public static function connect(string $amqpUrl): AMQPStreamConnection
    {
        $url = parse_url($amqpUrl);
        $vhost = isset($url['path']) && $url['path'] !== '/' && $url['path'] !== '' ? urldecode(substr($url['path'], 1)) : '/';
        return new AMQPStreamConnection($url['host'], $url['port'] ?? 5672, urldecode($url['user'] ?? 'guest'), urldecode($url['pass'] ?? 'guest'), $vhost);
    }

    public function declare(AMQPChannel $channel): void
    {
        $channel->exchange_declare($this->exchange, 'topic', false, true, false);
        $channel->exchange_declare($this->dlx, 'fanout', false, true, false);
        $channel->queue_declare($this->dlq, false, true, false, false);
        $channel->queue_bind($this->dlq, $this->dlx, '');
        $channel->queue_declare($this->queue, false, true, false, false, false, new AMQPTable(['x-dead-letter-exchange' => $this->dlx]));
        $channel->queue_bind($this->queue, $this->exchange, '#');
    }

    public static function messageCount(AMQPChannel $channel, string $queue): int
    {
        [, $count] = $channel->queue_declare($queue, true);
        return (int) $count;
    }
}

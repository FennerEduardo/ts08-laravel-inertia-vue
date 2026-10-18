<?php

declare(strict_types=1);

namespace App\Runtime;

/**
 * The event broker behind the outbox relay and the inbox consumer, chosen at run time so one image runs on any
 * cloud: GHK_BROKER=rabbitmq (AMQP_URL) or pubsub (PUBSUB_PROJECT_ID or GOOGLE_CLOUD_PROJECT; PUBSUB_EMULATOR_HOST).
 * Without GHK_BROKER the configured variables decide, then stack.messaging.
 * Azure Service Bus has no official PHP SDK: on Azure use self-managed RabbitMQ or CloudAMQP (AMQP_URL).
 */
final class Brokers
{
    /** Broker of the generated configuration (stack.messaging). */
    public const DEFAULT_KIND = 'rabbitmq';

    public static function env(string $key): ?string
    {
        $value = getenv($key);
        return $value === false || trim($value) === '' ? null : $value;
    }

    public static function kind(): string
    {
        if (self::env('GHK_BROKER') !== null) {
            return strtolower(trim((string) self::env('GHK_BROKER')));
        }
        if (self::env('AMQP_URL') !== null) {
            return 'rabbitmq';
        }
        if (self::env('PUBSUB_PROJECT_ID') !== null || self::env('PUBSUB_EMULATOR_HOST') !== null) {
            return 'pubsub';
        }
        return self::DEFAULT_KIND;
    }

    /** Whether the variables of the selected broker are set. */
    public static function configured(): bool
    {
        return self::kind() === 'pubsub'
            ? (self::env('PUBSUB_PROJECT_ID') ?? self::env('GOOGLE_CLOUD_PROJECT')) !== null
            : self::env('AMQP_URL') !== null;
    }

    public static function connect(): Broker
    {
        return match (self::kind()) {
            'rabbitmq' => RabbitMqBroker::connect(self::env('AMQP_URL') ?? throw new \RuntimeException('AMQP_URL is not set (amqp://user:password@host:5672)')),
            'pubsub' => PubSubBroker::fromEnv(),
            'servicebus' => throw new \RuntimeException('Azure Service Bus has no official PHP SDK: use RabbitMQ (self-managed or CloudAMQP) on Azure'),
            default => throw new \RuntimeException('Unsupported GHK_BROKER: ' . self::kind() . ' (rabbitmq, pubsub)'),
        };
    }
}

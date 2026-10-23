<?php

declare(strict_types=1);

namespace App\Runtime;

/**
 * Event bus destinations: publish to exchange (topic), consume from queue (subscription); dead-lettered messages
 * go to dlx and are read from dlq.
 */
final class Messaging
{
    /** The service's shared topology (same names as every other runtime and the generated IaC). */
    public const DEFAULT_PREFIX = 'laravel-inertia-vue-auth';

    public function __construct(public readonly string $exchange, public readonly string $queue, public readonly string $dlx, public readonly string $dlq)
    {
    }

    public static function forPrefix(string $prefix = self::DEFAULT_PREFIX): self
    {
        return new self("{$prefix}.events", "{$prefix}.consumer", "{$prefix}.dlx", "{$prefix}.dlq");
    }
}

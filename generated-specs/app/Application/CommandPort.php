<?php

declare(strict_types=1);

namespace App\Application;

/** What the HTTP layer needs from the runtime kernel (RuntimeCommandPort in production, a fake in tests). */
interface CommandPort
{
    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, aggregateId: string, eventType: ?string, version: ?int}
     */
    public function handle(string $tenantId, string $aggregateId, string $command, array $payload = [], ?string $idempotencyKey = null, ?string $traceparent = null, ?string $aggregateType = null): array;
}

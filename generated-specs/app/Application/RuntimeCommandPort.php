<?php

declare(strict_types=1);

namespace App\Application;

use App\Runtime\CommandService;

/** CommandPort over the runtime kernel's CommandService (DATABASE_URL). */
final class RuntimeCommandPort implements CommandPort
{
    private ?CommandService $service = null;

    public function __construct(private readonly string $databaseUrl)
    {
    }

    public function handle(string $tenantId, string $aggregateId, string $command, array $payload = [], ?string $idempotencyKey = null, ?string $traceparent = null, ?string $aggregateType = null): array
    {
        $this->service ??= new CommandService($this->databaseUrl);
        return $this->service->handle($tenantId, $aggregateId, $command, $payload, $idempotencyKey, $traceparent, $aggregateType);
    }
}

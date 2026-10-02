<?php

declare(strict_types=1);

namespace App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue;

use DateTimeImmutable;

final readonly class CheckoutAutenticadoConLaravelSanctumEInertiaVueDomainEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public CheckoutAutenticadoConLaravelSanctumEInertiaVueEventType $type,
        public string $aggregateId,
        public int $version,
        public DateTimeImmutable $occurredOn,
        public array $payload,
    ) {
    }
}

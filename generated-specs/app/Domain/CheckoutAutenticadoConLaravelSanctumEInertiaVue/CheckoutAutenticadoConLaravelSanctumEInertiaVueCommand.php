<?php

declare(strict_types=1);

namespace App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue;

final readonly class CheckoutAutenticadoConLaravelSanctumEInertiaVueCommand
{
    /** @param array<string, mixed> $payload */
    public function __construct(public string $id, public array $payload = [])
    {
    }
}

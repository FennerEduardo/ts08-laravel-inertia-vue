<?php

declare(strict_types=1);

namespace App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue;

enum CheckoutAutenticadoConLaravelSanctumEInertiaVueEventType: string
{
    case ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted = 'ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted';
    case CheckoutAutenticadoConLaravelSanctumEInertiaVueProcessed = 'CheckoutAutenticadoConLaravelSanctumEInertiaVueProcessed';
}

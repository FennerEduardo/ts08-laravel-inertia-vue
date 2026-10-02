<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregate;
use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\CheckoutAutenticadoConLaravelSanctumEInertiaVueCommand;
use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\CheckoutAutenticadoConLaravelSanctumEInertiaVueEventType;
use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\CheckoutAutenticadoConLaravelSanctumEInertiaVueState;
use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\DomainValidationException;
use PHPUnit\Framework\TestCase;

final class CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregateTest extends TestCase
{
    public function test_starts_in_the_initial_state_with_no_events(): void
    {
        $aggregate = new CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregate('agg-1');
        self::assertSame(CheckoutAutenticadoConLaravelSanctumEInertiaVueState::Pending, $aggregate->state());
        self::assertSame(0, $aggregate->version());
        self::assertSame([], $aggregate->pendingEvents());
    }

    public function test_rejects_an_aggregate_without_id(): void
    {
        $this->expectException(DomainValidationException::class);
        new CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregate('');
    }

    public function test_process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue_records_event_and_bumps_the_version(): void
    {
        $aggregate = new CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregate('agg-1');
        $event = $aggregate->processCheckoutAutenticadoConLaravelSanctumEInertiaVue(new CheckoutAutenticadoConLaravelSanctumEInertiaVueCommand('agg-1'));
        self::assertSame(CheckoutAutenticadoConLaravelSanctumEInertiaVueEventType::ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted, $event->type);
        self::assertSame(1, $event->version);
        self::assertSame(1, $aggregate->version());
        self::assertCount(1, $aggregate->pendingEvents());
    }

    public function test_process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue_rejects_a_command_without_id(): void
    {
        $aggregate = new CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregate('agg-1');
        try {
            $aggregate->processCheckoutAutenticadoConLaravelSanctumEInertiaVue(new CheckoutAutenticadoConLaravelSanctumEInertiaVueCommand(''));
            self::fail('Expected DomainValidationException');
        } catch (DomainValidationException) {
            self::assertSame([], $aggregate->pendingEvents());
        }
    }
}

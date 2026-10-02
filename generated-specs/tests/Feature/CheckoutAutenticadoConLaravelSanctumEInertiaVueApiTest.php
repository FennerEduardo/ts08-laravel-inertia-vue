<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class CheckoutAutenticadoConLaravelSanctumEInertiaVueApiTest extends TestCase
{
    public function test_health_endpoint(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_executes_a_domain_command(): void
    {
        $this->postJson('/api/v1/checkout-autenticado-con-laravel-sanctum-e-inertia-vue/agg-api/process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue', ['source' => 'api-test'])
            ->assertCreated()
            ->assertExactJson(['type' => 'ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted', 'aggregateId' => 'agg-api', 'version' => 1]);
    }

    public function test_unknown_command_returns_404(): void
    {
        $this->postJson('/api/v1/checkout-autenticado-con-laravel-sanctum-e-inertia-vue/agg-api/does_not_exist')->assertNotFound();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\CommandPort;
use App\Auth\JwtTenant;
use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\DomainValidationException;
use Tests\TestCase;

final class CheckoutAutenticadoConLaravelSanctumEInertiaVueApiTest extends TestCase
{
    private const SECRET = 'unit-test-secret-with-at-least-32-chars';

    /** @var list<array<int, mixed>> */
    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRET]);
        $test = $this;
        $this->app->instance(CommandPort::class, new class ($test) implements CommandPort {
            public function __construct(private readonly CheckoutAutenticadoConLaravelSanctumEInertiaVueApiTest $test)
            {
            }

            public function handle(string $tenantId, string $aggregateId, string $command, array $payload = [], ?string $idempotencyKey = null, ?string $traceparent = null, ?string $aggregateType = null): array
            {
                $this->test->calls[] = [$tenantId, $aggregateId, $command, $payload];
                if ($command === 'does_not_exist') {
                    throw new DomainValidationException("Unknown command {$command}");
                }
                return ['status' => $idempotencyKey === 'seen' ? 'replayed' : 'created', 'aggregateId' => $aggregateId, 'eventType' => 'ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted', 'version' => 1];
            }
        });
    }

    private function bearer(array $claims, string $secret = self::SECRET): array
    {
        return ['Authorization' => 'Bearer ' . JwtTenant::sign($claims, $secret)];
    }

    public function test_health_endpoint(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_runs_a_command_for_the_token_tenant(): void
    {
        $this->postJson('/api/v1/checkout-autenticado-con-laravel-sanctum-e-inertia-vue/agg-api/process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue', ['source' => 'api-test'], $this->bearer([JwtTenant::TENANT_CLAIM => 'acme']) + ['X-Tenant-Id' => 'someone-else'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'created');
        self::assertSame(['acme', 'agg-api', 'process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue', ['source' => 'api-test']], end($this->calls));
    }

    public function test_idempotent_replay_returns_200(): void
    {
        $this->postJson('/api/v1/checkout-autenticado-con-laravel-sanctum-e-inertia-vue/agg-api/process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue', [], $this->bearer([JwtTenant::TENANT_CLAIM => 'acme']) + ['Idempotency-Key' => 'seen'])->assertOk();
    }

    public function test_rejects_missing_or_invalid_tokens_and_x_tenant_id(): void
    {
        $url = '/api/v1/checkout-autenticado-con-laravel-sanctum-e-inertia-vue/agg-api/process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue';
        $this->postJson($url)->assertUnauthorized();
        $this->postJson($url, [], ['X-Tenant-Id' => 'acme'])->assertUnauthorized();
        $this->postJson($url, [], $this->bearer(['sub' => 'u1']))->assertUnauthorized();
        $this->postJson($url, [], $this->bearer([JwtTenant::TENANT_CLAIM => 'acme'], 'wrong'))->assertUnauthorized();
    }

    public function test_domain_validation_error_is_422(): void
    {
        $this->postJson('/api/v1/checkout-autenticado-con-laravel-sanctum-e-inertia-vue/agg-api/does_not_exist', [], $this->bearer([JwtTenant::TENANT_CLAIM => 'acme']))->assertStatus(422);
    }
}

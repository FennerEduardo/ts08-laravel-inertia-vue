<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\JwtTenant;
use App\Runtime\Database;
use App\Runtime\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** HTTP contract of the application layer against PostgreSQL (needs DATABASE_URL). */
#[Group('integration')]
final class CheckoutAutenticadoConLaravelSanctumEInertiaVueHttpTest extends TestCase
{
    private const SECRET = 'integration-test-secret-with-32-chars!';

    public function test_runs_a_command_into_the_outbox_for_the_token_tenant_idempotently(): void
    {
        $url = (string) getenv('DATABASE_URL');
        Schema::migrate($url);
        config(['app.jwt_secret' => self::SECRET]);
        $tenant = 'tenant-' . bin2hex(random_bytes(6));
        $id = 'agg-' . bin2hex(random_bytes(6));
        $headers = ['Authorization' => 'Bearer ' . JwtTenant::sign([JwtTenant::TENANT_CLAIM => $tenant], self::SECRET), 'Idempotency-Key' => bin2hex(random_bytes(8)), 'X-Tenant-Id' => 'someone-else'];
        $path = '/api/v1/checkout-autenticado-con-laravel-sanctum-e-inertia-vue/' . $id . '/process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue';

        $this->postJson($path, [], ['X-Tenant-Id' => $tenant])->assertUnauthorized();
        $this->postJson($path, [], $headers)->assertStatus(202);
        $this->postJson($path, [], $headers)->assertOk()->assertJsonPath('status', 'replayed');

        $rows = Database::rows(Database::connect($url), 'SELECT tenant_id FROM ghk_outbox WHERE aggregate_id = ?', [$id]);
        self::assertSame([['tenant_id' => $tenant]], $rows);
    }
}

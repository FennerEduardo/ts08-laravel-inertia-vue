<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\CommandPort;
use App\Auth\JwtTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/v1/checkout-autenticado-con-laravel-sanctum-e-inertia-vue/{id}/{command} (process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue). */
// ghk:implements CheckoutAutenticadoConLaravelSanctumEInertiaVue
final class CheckoutAutenticadoConLaravelSanctumEInertiaVueController
{
    private const STATUS = ['created' => 202, 'replayed' => 200, 'in-progress' => 409];

    public function __construct(private readonly CommandPort $commands)
    {
    }

    public function execute(Request $request, string $id, string $command): JsonResponse
    {
        $tenant = JwtTenant::tenantFrom($request->header('Authorization'), (string) (config('app.jwt_secret') ?? env('JWT_SECRET', '')));
        if ($tenant === null) {
            return response()->json(['detail' => 'A valid bearer token with a tenant is required'], 401);
        }
        try {
            $result = $this->commands->handle($tenant, $id, $command, $request->all(), $request->header('Idempotency-Key'), $request->header('traceparent'), 'CheckoutAutenticadoConLaravelSanctumEInertiaVue');
        } catch (\DomainException $e) {
            // Every feature's DomainValidationException extends \DomainException.
            return response()->json(['detail' => $e->getMessage()], 422);
        }
        return response()->json($result, self::STATUS[$result['status']] ?? 200);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregate;
use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\CheckoutAutenticadoConLaravelSanctumEInertiaVueCommand;
use App\Domain\CheckoutAutenticadoConLaravelSanctumEInertiaVue\DomainValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CheckoutAutenticadoConLaravelSanctumEInertiaVueController
{
    private const COMMANDS = [
        'process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue' => 'processCheckoutAutenticadoConLaravelSanctumEInertiaVue',
    ];

    public function execute(Request $request, string $id, string $command): JsonResponse
    {
        $method = self::COMMANDS[$command] ?? null;
        if ($method === null) {
            return response()->json(['detail' => "Unknown command {$command}"], 404);
        }
        try {
            // In-memory aggregate: replace with a repository + outbox.
            $event = (new CheckoutAutenticadoConLaravelSanctumEInertiaVueAggregate($id))->{$method}(new CheckoutAutenticadoConLaravelSanctumEInertiaVueCommand($id, $request->all()));
        } catch (DomainValidationException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        }
        return response()->json(['type' => $event->type->value, 'aggregateId' => $event->aggregateId, 'version' => $event->version], 201);
    }
}

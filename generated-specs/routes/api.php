<?php

use App\Http\Controllers\CheckoutAutenticadoConLaravelSanctumEInertiaVueController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/checkout-autenticado-con-laravel-sanctum-e-inertia-vue/{id}/{command}', [CheckoutAutenticadoConLaravelSanctumEInertiaVueController::class, 'execute']);

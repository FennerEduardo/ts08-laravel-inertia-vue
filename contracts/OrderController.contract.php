<?php

namespace App\Contracts;

interface OrderControllerContract
{
    /**
     * @param \App\Http\Requests\StoreOrderRequest $request
     * @return \Inertia\Response|\Illuminate\Http\JsonResponse
     */
    public function store(array $validatedData);

    /**
     * @param string $idempotencyKey
     * @return bool
     */
    public function isProcessed(string $idempotencyKey): bool;

    /**
     * @param int $orderId
     * @return array
     */
    public function getOrderSummary(int $orderId): array;
}

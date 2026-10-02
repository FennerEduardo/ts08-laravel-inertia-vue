import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient, useCheckoutAutenticadoConLaravelSanctumEInertiaVueStore } from './checkout-autenticado-con-laravel-sanctum-e-inertia-vue.store';
import type { CheckoutAutenticadoConLaravelSanctumEInertiaVueClient } from '../api/checkout-autenticado-con-laravel-sanctum-e-inertia-vue-client';

describe('checkoutAutenticadoConLaravelSanctumEInertiaVue store', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('records the event returned by the backend', async () => {
    const result = { type: 'ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted', aggregateId: 'agg-1', version: 1 };
    setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient({ execute: vi.fn().mockResolvedValue(result) } as unknown as CheckoutAutenticadoConLaravelSanctumEInertiaVueClient);
    const store = useCheckoutAutenticadoConLaravelSanctumEInertiaVueStore();

    await store.execute('agg-1', 'process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue');

    expect(store.events).toEqual([result]);
    expect(store.error).toBeNull();
  });

  it('keeps the error message when the command fails', async () => {
    setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient({ execute: vi.fn().mockRejectedValue(new Error('Command id is required')) } as unknown as CheckoutAutenticadoConLaravelSanctumEInertiaVueClient);
    const store = useCheckoutAutenticadoConLaravelSanctumEInertiaVueStore();

    await store.execute('agg-1', 'process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue');

    expect(store.error).toBe('Command id is required');
  });
});

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel from './CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel.vue';
import { setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient } from '../stores/checkout-autenticado-con-laravel-sanctum-e-inertia-vue.store';
import type { CheckoutAutenticadoConLaravelSanctumEInertiaVueClient } from '../api/checkout-autenticado-con-laravel-sanctum-e-inertia-vue-client';

describe('CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('executes a command and lists the resulting event', async () => {
    const execute = vi.fn().mockResolvedValue({ type: 'ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted', aggregateId: 'agg-1', version: 1 });
    setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient({ execute } as unknown as CheckoutAutenticadoConLaravelSanctumEInertiaVueClient);
    const wrapper = mount(CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel);

    await wrapper.get('[data-test="aggregate-id"]').setValue('agg-1');
    await wrapper.get('[data-test="process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue"]').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted v1');
    expect(execute).toHaveBeenCalledWith('agg-1', 'process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue', undefined, expect.objectContaining({ idempotencyKey: expect.any(String) }));
  });

  it('disables commands until an aggregate id is entered', () => {
    const wrapper = mount(CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel);
    expect(wrapper.get('[data-test="process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue"]').attributes('disabled')).toBeDefined();
  });
});

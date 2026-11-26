import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel from './CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel.vue';
import { setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient } from '../stores/checkout-autenticado-con-laravel-sanctum-e-inertia-vue.store';
import type { CheckoutAutenticadoConLaravelSanctumEInertiaVueClient } from '../api/checkout-autenticado-con-laravel-sanctum-e-inertia-vue-client';

const emptyPage = { items: [], page: 1, size: 20, total: 0 };

describe('CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('executes a command and lists the resulting event', async () => {
    const execute = vi.fn().mockResolvedValue({ type: 'ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted', aggregateId: 'agg-1', version: 1 });
    setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient({ execute, list: vi.fn().mockResolvedValue(emptyPage) } as unknown as CheckoutAutenticadoConLaravelSanctumEInertiaVueClient);
    const wrapper = mount(CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel);

    await wrapper.get('[data-test="aggregate-id"]').setValue('agg-1');
    await wrapper.get('[data-test="process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue"]').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('ProcessCheckoutAutenticadoConLaravelSanctumEInertiaVueCompleted v1');
    expect(execute).toHaveBeenCalledWith('agg-1', 'process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue', expect.any(Object), expect.objectContaining({ idempotencyKey: expect.any(String) }));
  });

  it('shows the items the API reads', async () => {
    setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient({ list: vi.fn().mockResolvedValue({ items: [{ id: 'agg-9', state: 'PENDING', version: 2 }], page: 1, size: 20, total: 1 }) } as unknown as CheckoutAutenticadoConLaravelSanctumEInertiaVueClient);
    const wrapper = mount(CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel);
    await flushPromises();

    expect(wrapper.get('[data-test="item-agg-9"]').text()).toContain('agg-9');
  });

  it('disables commands until an aggregate id is entered', () => {
    setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient({ list: vi.fn().mockResolvedValue(emptyPage) } as unknown as CheckoutAutenticadoConLaravelSanctumEInertiaVueClient);
    const wrapper = mount(CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel);
    expect(wrapper.get('[data-test="process_checkout_autenticado_con_laravel_sanctum_e_inertia_vue"]').attributes('disabled')).toBeDefined();
  });
});

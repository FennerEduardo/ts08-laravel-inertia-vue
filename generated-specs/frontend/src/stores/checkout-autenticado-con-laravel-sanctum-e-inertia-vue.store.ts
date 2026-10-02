import { defineStore } from 'pinia';
import { ref } from 'vue';
import { CommandName, CommandResult, createCheckoutAutenticadoConLaravelSanctumEInertiaVueClient, CheckoutAutenticadoConLaravelSanctumEInertiaVueClient } from '../api/checkout-autenticado-con-laravel-sanctum-e-inertia-vue-client';

let client: CheckoutAutenticadoConLaravelSanctumEInertiaVueClient = createCheckoutAutenticadoConLaravelSanctumEInertiaVueClient({ baseUrl: import.meta.env.VITE_API_URL ?? '' });

/** Test/composition hook: swap the API client (e.g. a fake in unit tests). */
export function setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient(next: CheckoutAutenticadoConLaravelSanctumEInertiaVueClient): void {
  client = next;
}

export const useCheckoutAutenticadoConLaravelSanctumEInertiaVueStore = defineStore('checkoutAutenticadoConLaravelSanctumEInertiaVue', () => {
  const events = ref<CommandResult[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function execute(id: string, command: CommandName, payload?: Record<string, unknown>): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      events.value.push(await client.execute(id, command, payload, { idempotencyKey: crypto.randomUUID() }));
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Request failed';
    } finally {
      loading.value = false;
    }
  }

  return { events, loading, error, execute };
});

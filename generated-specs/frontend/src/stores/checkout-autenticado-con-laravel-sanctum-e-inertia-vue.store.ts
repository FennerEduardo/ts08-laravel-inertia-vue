import { defineStore } from 'pinia';
import { ref } from 'vue';
import { CommandName, CommandResult, createCheckoutAutenticadoConLaravelSanctumEInertiaVueClient, CheckoutAutenticadoConLaravelSanctumEInertiaVueClient, CheckoutAutenticadoConLaravelSanctumEInertiaVueItem } from '../api/checkout-autenticado-con-laravel-sanctum-e-inertia-vue-client';

let client: CheckoutAutenticadoConLaravelSanctumEInertiaVueClient = createCheckoutAutenticadoConLaravelSanctumEInertiaVueClient({ baseUrl: import.meta.env.VITE_API_URL ?? '' });

/** Test/composition hook: swap the API client (e.g. a fake in unit tests). */
export function setCheckoutAutenticadoConLaravelSanctumEInertiaVueClient(next: CheckoutAutenticadoConLaravelSanctumEInertiaVueClient): void {
  client = next;
}

export const useCheckoutAutenticadoConLaravelSanctumEInertiaVueStore = defineStore('checkoutAutenticadoConLaravelSanctumEInertiaVue', () => {
  const events = ref<CommandResult[]>([]);
  const items = ref<CheckoutAutenticadoConLaravelSanctumEInertiaVueItem[]>([]);
  const total = ref(0);
  const loading = ref(false);
  const error = ref<string | null>(null);

  /** Reads the tenant's Checkout Autenticado con Laravel Sanctum e Inertia Vue aggregates (the List query). */
  async function load(filters: Record<string, string> = {}): Promise<void> {
    error.value = null;
    try {
      const page = await client.list(filters);
      items.value = page.items;
      total.value = page.total;
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Request failed';
    }
  }

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

  return { events, items, total, loading, error, load, execute };
});

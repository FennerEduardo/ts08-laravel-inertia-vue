// --------------------------------------------------------------------------
// Pinia Store (Vue 3 Composition API)
// --------------------------------------------------------------------------
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

export const useCheckoutAutenticadoConLaravelSanctumEInertiaVueStore = defineStore('checkoutAutenticadoConLaravelSanctumEInertiaVue', () => {
  // State
  const data = ref<any[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const tenantId = ref<string | null>(null);

  // Getters
  const hasData = computed(() => data.value.length > 0);
  const activeCount = computed(() => data.value.length);

  // Actions
  async function fetchAll() {
    loading.value = true;
    error.value = null;
    try {
      const response = await axios.get('/api/checkoutAutenticadoConLaravelSanctumEInertiaVue');
      data.value = response.data;
    } catch (err: any) {
      error.value = err.response?.data?.message || err.message || 'Error fetching data';
    } finally {
      loading.value = false;
    }
  }

  async function executeCommand(commandPayload: any, idempotencyKey?: string) {
    loading.value = true;
    error.value = null;
    try {
      const headers: Record<string, string> = {};
      if (tenantId.value) headers['X-Tenant-ID'] = tenantId.value;
      if (idempotencyKey) headers['X-Idempotency-Key'] = idempotencyKey;

      const response = await axios.post('/api/checkoutAutenticadoConLaravelSanctumEInertiaVue/commands', commandPayload, { headers });
      return response.data;
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Error en comando';
      throw err;
    } finally {
      loading.value = false;
    }
  }

  function handleRealtimeEvent(event: any) {
    data.value.unshift(event);
  }

  return {
    data,
    loading,
    error,
    tenantId,
    hasData,
    activeCount,
    fetchAll,
    executeCommand,
    handleRealtimeEvent
  };
});

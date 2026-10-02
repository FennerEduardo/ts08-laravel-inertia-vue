<script setup lang="ts">
import { ref } from 'vue';
import { COMMANDS } from '../api/checkout-autenticado-con-laravel-sanctum-e-inertia-vue-client';
import { useCheckoutAutenticadoConLaravelSanctumEInertiaVueStore } from '../stores/checkout-autenticado-con-laravel-sanctum-e-inertia-vue.store';

const store = useCheckoutAutenticadoConLaravelSanctumEInertiaVueStore();
const aggregateId = ref('');
</script>

<template>
  <section aria-label="Checkout Autenticado con Laravel Sanctum e Inertia Vue">
    <h1>Checkout Autenticado con Laravel Sanctum e Inertia Vue</h1>
    <label>
      Aggregate id
      <input v-model="aggregateId" data-test="aggregate-id" />
    </label>
    <button
      v-for="command in COMMANDS"
      :key="command"
      :data-test="command"
      :disabled="!aggregateId || store.loading"
      @click="store.execute(aggregateId, command)"
    >
      {{ command }}
    </button>
    <p v-if="store.error" role="alert">{{ store.error }}</p>
    <ul aria-label="events">
      <li v-for="e in store.events" :key="e.aggregateId + '-' + e.version">{{ e.type }} v{{ e.version }}</li>
    </ul>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { COMMANDS, COMMAND_FIELDS, FIELDS, type CommandName } from '../api/checkout-autenticado-con-laravel-sanctum-e-inertia-vue-client';
import { useCheckoutAutenticadoConLaravelSanctumEInertiaVueStore } from '../stores/checkout-autenticado-con-laravel-sanctum-e-inertia-vue.store';

const store = useCheckoutAutenticadoConLaravelSanctumEInertiaVueStore();
const aggregateId = ref('');
const form = reactive<Record<string, string>>({});
/** The columns of the table: the first fields the commands write. */
const columns = computed(() => FIELDS.slice(0, 6));
/** Every field a command takes, once: the inputs of the form. */
const inputs = [...new Map(Object.values(COMMAND_FIELDS).flat().map(f => [f.name, f])).values()];

/** The command's own fields that were filled in, as its payload. */
function payloadFor(command: CommandName): Record<string, unknown> {
  return Object.fromEntries(COMMAND_FIELDS[command].filter(f => form[f.name] !== undefined && form[f.name] !== '').map(f => [f.name, form[f.name]]));
}

async function run(command: CommandName): Promise<void> {
  await store.execute(aggregateId.value, command, payloadFor(command));
  if (!store.error) await store.load();
}

/** A new aggregate: a fresh id, empty fields. */
function newId(): void {
  aggregateId.value = crypto.randomUUID();
  for (const name of Object.keys(form)) form[name] = '';
}

function select(id: string): void {
  aggregateId.value = id;
  const item = store.items.find(i => i.id === id);
  for (const name of FIELDS) form[name] = item?.[name] === undefined ? '' : String(item[name]);
}

onMounted(() => store.load());
</script>

<template>
  <section aria-label="Checkout Autenticado con Laravel Sanctum e Inertia Vue">
    <h1>Checkout Autenticado con Laravel Sanctum e Inertia Vue</h1>
    <label>
      Aggregate id
      <input v-model="aggregateId" data-test="aggregate-id" />
    </label>
    <button type="button" data-test="new-id" @click="newId">New</button>
    <label v-for="field in inputs" :key="field.name">
      {{ field.name }}<span v-if="field.rules.includes('required')"> *</span>
      <input v-model="form[field.name]" :data-test="'field-' + field.name" />
    </label>
    <button
      v-for="command in COMMANDS"
      :key="command"
      :data-test="command"
      :disabled="!aggregateId || store.loading"
      @click="run(command)"
    >
      {{ command }}
    </button>
    <p v-if="store.error" role="alert">{{ store.error }}</p>
    <table aria-label="items">
      <thead>
        <tr><th>id</th><th v-for="c in columns" :key="c">{{ c }}</th></tr>
      </thead>
      <tbody>
        <tr v-for="item in store.items" :key="item.id" :data-test="'item-' + item.id" @click="select(item.id)">
          <td>{{ item.id }}</td><td v-for="c in columns" :key="c">{{ item[c] }}</td>
        </tr>
      </tbody>
    </table>
    <ul aria-label="events">
      <li v-for="e in store.events" :key="e.aggregateId + '-' + e.version">{{ e.type }} v{{ e.version }}</li>
    </ul>
  </section>
</template>

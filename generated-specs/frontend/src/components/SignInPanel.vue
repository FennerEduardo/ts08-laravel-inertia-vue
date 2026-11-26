<script setup lang="ts">
import { ref } from 'vue';
import { currentSession, signIn, signOut } from '../api/auth';

const email = ref('');
const password = ref('');
const user = ref(currentSession()?.user);
const error = ref<string | null>(null);

async function submit(): Promise<void> {
  error.value = null;
  try {
    user.value = (await signIn(email.value, password.value, { baseUrl: import.meta.env.VITE_API_URL ?? '' })).user;
    location.reload();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Sign-in failed';
  }
}

function leave(): void {
  signOut();
  user.value = undefined;
  location.reload();
}
</script>

<template>
  <section aria-label="Sign in">
    <p v-if="user" data-test="signed-in">{{ user.email }} ({{ user.roles.join(', ') }}) <button type="button" @click="leave">Sign out</button></p>
    <form v-else @submit.prevent="submit">
      <input v-model="email" type="email" placeholder="Email" data-test="email" />
      <input v-model="password" type="password" placeholder="Password" data-test="password" />
      <button type="submit" data-test="sign-in">Sign in</button>
      <p v-if="error" role="alert">{{ error }}</p>
    </form>
  </section>
</template>

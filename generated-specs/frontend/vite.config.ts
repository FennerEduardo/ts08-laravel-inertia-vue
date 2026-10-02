/// <reference types="vitest" />
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  server: { proxy: { '/api': process.env.VITE_API_URL ?? 'http://localhost:3000' } },
  test: { environment: 'jsdom', globals: true }
});

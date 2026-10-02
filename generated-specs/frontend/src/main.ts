import { createApp } from 'vue';
import { createPinia } from 'pinia';
import CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel from './components/CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel.vue';

createApp(CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel).use(createPinia()).mount('#app');

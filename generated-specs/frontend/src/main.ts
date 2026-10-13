import { createApp, h } from 'vue';
import { createPinia } from 'pinia';
import CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel from './components/CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel.vue';

const App = { render: () => [h(CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel)] };

createApp(App).use(createPinia()).mount('#app');

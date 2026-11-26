import { createApp, h } from 'vue';
import { createPinia } from 'pinia';
import SignInPanel from './components/SignInPanel.vue';
import CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel from './components/CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel.vue';

const App = { render: () => [h(SignInPanel), h(CheckoutAutenticadoConLaravelSanctumEInertiaVuePanel)] };

createApp(App).use(createPinia()).mount('#app');

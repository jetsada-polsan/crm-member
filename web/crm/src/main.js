import { createApp } from 'vue';
import { VueQueryPlugin } from '@tanstack/vue-query';
import App from './App.vue';
import { router } from './router.js';
import { crmQueryClient } from './composables/useCrmSession.js';
import '../../shared/base.css';

createApp(App).use(router).use(VueQueryPlugin, { queryClient: crmQueryClient }).mount('#app');

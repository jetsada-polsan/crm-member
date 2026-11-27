import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

const root = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  root,
  plugins: [vue()],
  build: {
    outDir: 'dist',
    assetsDir: 'member-assets',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules/vue-router') || id.includes('node_modules/@tanstack'))
            return 'vendor-router-query';
          if (id.includes('node_modules/vue')) return 'vendor-vue';
        },
      },
    },
  },
  server: { port: 5174 },
});

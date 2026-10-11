import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { sasSpaFallback } from './sas/vite-fallback.js';

export default defineConfig({
  base: '/',
  plugins: [sasSpaFallback(), react()],
  build: {
    // A SAS-only build must leave the public, admin, and player outputs intact.
    emptyOutDir: false,
    rollupOptions: {
      input: fileURLToPath(new URL('./sas/index.html', import.meta.url)),
    },
  },
});

import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [sveltekit()],
  server: {
    host: '127.0.0.1',
    port: 5174,
    strictPort: true,
    proxy: {
      // Forward API + Sanctum endpoints to the POSO Laravel app on :8010.
      '/api': { target: 'http://localhost:8002', changeOrigin: false },
      '/sanctum': { target: 'http://localhost:8002', changeOrigin: false },
      '/auth': { target: 'http://localhost:8002', changeOrigin: false },
    },
  },
});

import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [sveltekit()],
  server: {
    host: '0.0.0.0',
    port: 5175,
    strictPort: true,
    allowedHosts: true,
    proxy: {
      // Forward API, protected storage, Sanctum, and SSO endpoints to Laravel.
      '/api': { target: 'http://127.0.0.1:8000', changeOrigin: false },
      '/sanctum': { target: 'http://127.0.0.1:8000', changeOrigin: false },
      '/auth': { target: 'http://127.0.0.1:8000', changeOrigin: false },
      '/sso': { target: 'http://127.0.0.1:8000', changeOrigin: false },
      '/oidc': { target: 'http://127.0.0.1:8000', changeOrigin: false },
      '/storage': { target: 'http://127.0.0.1:8000', changeOrigin: false },
    },
  },
});

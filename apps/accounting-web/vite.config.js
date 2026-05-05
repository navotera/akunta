import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [sveltekit()],
  server: {
    host: '127.0.0.1',
    port: 5175,
    strictPort: true,
    allowedHosts: ['accounting.akunta.local'],
    proxy: {
      // Forward API + Sanctum + SSO bounce endpoints to the Laravel app on :8000.
      '/api': { target: 'http://localhost:8000', changeOrigin: false },
      '/sanctum': { target: 'http://localhost:8000', changeOrigin: false },
      '/auth': { target: 'http://localhost:8000', changeOrigin: false },
      '/sso': { target: 'http://localhost:8000', changeOrigin: false },
      '/oidc': { target: 'http://localhost:8000', changeOrigin: false },
    },
  },
});

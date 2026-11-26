import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import type { Connect, Plugin } from 'vite'
import { defineConfig } from 'vitest/config'

// The diner's app is its own entry (D-10): a table's QR opens /m/<token>.
const toDiner: Connect.NextHandleFunction = (req, _res, next) => {
  if (req.url?.startsWith('/m/')) req.url = '/diner.html'
  next()
}
const dinerRoute: Plugin = {
  name: 'diner-route',
  configureServer: (server) => { server.middlewares.use(toDiner) },
  configurePreviewServer: (server) => { server.middlewares.use(toDiner) },
}

export default defineConfig({
  plugins: [vue(), tailwindcss(), dinerRoute],
  build: { rollupOptions: { input: ['index.html', 'diner.html'] } },
  server: { proxy: { '/api': process.env.VITE_API_PROXY ?? 'http://localhost:8100' } },
  test: { environment: 'jsdom', include: ['tests/**/*.test.ts'] },
})

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  server: { proxy: { '/api': process.env.VITE_API_PROXY ?? 'http://localhost:8100' } },
  test: { environment: 'jsdom', include: ['tests/**/*.test.ts'] },
})

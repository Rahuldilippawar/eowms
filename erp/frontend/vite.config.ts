/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

// The production build is served by Laravel: assets live under /app/,
// and Laravel returns app/index.html for every non-API path.
export default defineConfig({
  base: '/app/',
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  build: {
    outDir: '../backend/public/app',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        // Libraries change rarely — separate chunks stay cached between releases.
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          data: ['@tanstack/react-query', 'axios', 'zod', 'react-hook-form', '@hookform/resolvers'],
          ui: ['@radix-ui/react-dialog', '@radix-ui/react-dropdown-menu', 'i18next', 'react-i18next'],
        },
      },
    },
  },
  server: {
    port: 5173,
    // Same-origin in development too, so session cookies + CSRF behave as in production.
    proxy: {
      '/api': 'http://127.0.0.1:8000',
      '/sanctum': 'http://127.0.0.1:8000',
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    css: false,
  },
})

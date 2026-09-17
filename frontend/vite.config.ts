import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
import { fileURLToPath, URL } from 'node:url'

// Dev server: bind to localhost unless VITE_DEV_HOST=true (needed for Docker).
// Network-exposed Vite was historically vulnerable to arbitrary file reads via HMR WS
// (CVE-2026-39363); keep host locked down by default.
const exposeHost = process.env.VITE_DEV_HOST === 'true'

export default defineConfig({
  base: '/',
  plugins: [
    vue({
      // Never enable runtime template compilation — prevents SSTI/RCE class issues.
      template: {
        compilerOptions: {
          isCustomElement: () => false,
        },
      },
    }),
    vuetify({ autoImport: true }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      // Force runtime-only build (no compiler in the browser bundle).
      vue: 'vue/dist/vue.runtime.esm-bundler.js',
    },
  },
  server: {
    host: exposeHost,
    port: 3006,
    strictPort: true,
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET || 'http://localhost:8089',
        changeOrigin: true,
      },
    },
  },
  preview: {
    host: exposeHost,
    port: 3006,
    strictPort: true,
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET || 'http://localhost:8089',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
    // Do not ship source maps that reverse-engineer the admin UI.
    minify: 'esbuild',
  },
})

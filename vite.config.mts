import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  publicDir: false,
  build: {
    manifest: true,
    outDir: 'public/build',
    emptyOutDir: true,
    rollupOptions: { input: 'resources/js/app.ts' },
  },
  server: { host: '0.0.0.0', port: 5173, strictPort: true },
})

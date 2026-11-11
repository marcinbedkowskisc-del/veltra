import { resolve } from 'node:path'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'interactive-preview-dist',
    rollupOptions: {
      input: {
        index: resolve(import.meta.dirname, 'visual-preview.html'),
      },
    },
  },
})

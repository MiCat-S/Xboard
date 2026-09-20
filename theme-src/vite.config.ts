import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// 构建产物直接落到 theme/Nova/assets，文件名固定，
// 这样 dashboard.blade.php 里的引用不用跟着哈希变。
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: '../theme/Nova/assets',
    emptyOutDir: true,
    chunkSizeWarningLimit: 1500,
    rollupOptions: {
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'app-[name].js',
        assetFileNames: 'app.[ext]',
      },
    },
  },
  server: {
    port: 5173,
    proxy: {
      // 本地 vite dev 时把 API 打到 php artisan serve
      '/api': 'http://127.0.0.1:8000',
    },
  },
})

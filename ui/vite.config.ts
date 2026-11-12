import { defineConfig } from 'vitest/config'
import preact from '@preact/preset-vite'

// Fixed output names (assets/app.js, assets/app.css): plugin/app/index.php adds a content-hash query string, so
// the archive stays deterministic and the file names stay stable. base './' keeps it working under any URL prefix.
export default defineConfig({
  plugins: [preact()],
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    modulePreload: false,
    sourcemap: false,
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        entryFileNames: 'assets/app.js',
        chunkFileNames: 'assets/[name].js',
        assetFileNames: (info) => (info.names?.some((n) => n.endsWith('.css')) ? 'assets/app.css' : 'assets/[name][extname]'),
      },
    },
  },
  test: { environment: 'jsdom' },
})

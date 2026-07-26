import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import path from 'node:path';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [
    react(),
    laravel({
      input: [
        'resources/css/app.css',
        'resources/js/phr/pages.tsx',
        'resources/js/phr/imaging/explore3d/standalone.tsx',
      ],
      refresh: true,
    }),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'resources/js'),
    },
  },
  // Explore-in-3D's DICOM volume decode pipeline needs ES-module workers.
  worker: {
    format: 'es',
  },
  build: {
    rollupOptions: {
      external: (id) => /\.test\.[tj]sx?$/.test(id) || id.includes('/__tests__/'),
      output: {
        manualChunks: (id) => {
          if (id.includes('node_modules')) {
            if (id.includes('react/') || id.includes('react-dom/')) {
              return 'vendor';
            }
            if (
              id.includes('@base-ui/react') ||
              id.includes('class-variance-authority') ||
              id.includes('clsx') ||
              id.includes('tailwind-merge')
            ) {
              return 'ui-core';
            }
            if (id.includes('lucide-react') || id.includes('zod') || id.includes('date-fns')) {
              return 'utils';
            }
            if (id.includes('three') || id.includes('dicom-parser') || id.includes('pdfjs-dist')) {
              return 'imaging';
            }
          }
        },
      },
    },
  },
});

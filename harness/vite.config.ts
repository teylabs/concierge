import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

/**
 * Browser test harness only. Serves the package pages with typed fixtures and
 * a scripted fake adapter on the lane's dedicated port. Not shipped to hosts.
 */
export default defineConfig({
    root: fileURLToPath(new URL('.', import.meta.url)),
    plugins: [vue(), tailwindcss()],
    server: {
        host: '127.0.0.1',
        port: 8152,
        strictPort: true,
    },
    preview: {
        host: '127.0.0.1',
        port: 8152,
        strictPort: true,
    },
});

import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// The documented host integration: alias the Composer-installed sources and
// dedupe framework packages so vendor files resolve the host's copies.
export default defineConfig({
    plugins: [vue(), tailwindcss()],
    resolve: {
        alias: {
            '@tey/concierge': fileURLToPath(
                new URL('./vendor/tey/concierge/resources/js', import.meta.url),
            ),
        },
        dedupe: ['vue', '@inertiajs/vue3'],
    },
    build: {
        rollupOptions: { input: 'resources/js/app.ts' },
        manifest: true,
    },
});

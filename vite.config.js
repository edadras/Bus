import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // One shared bundle plus a per-surface entry, so the passenger PWA
            // does not ship the admin panel's charting library and vice versa.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/landing.js',
                'resources/js/admin.js',
                'resources/js/passenger.js',
                'resources/js/driver.js',
                'resources/js/merchant.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks: {
                    leaflet: ['leaflet'],
                    charts: ['chart.js'],
                    realtime: ['laravel-echo', 'pusher-js'],
                },
            },
        },
    },
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});

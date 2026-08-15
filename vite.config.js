import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // One shared bundle plus a per-surface entry, so the passenger PWA
            // does not ship the admin panel's charting library and vice versa.
            // Four entries: the shared runtime, the landing page, the admin
            // panel, and the lightweight public web viewer. The passenger,
            // driver and merchant apps are native Flutter builds (see apps/),
            // so they ship no JavaScript here.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/landing.js',
                'resources/js/admin.js',
                'resources/js/admin-login.js',
                'resources/js/viewer.js',
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

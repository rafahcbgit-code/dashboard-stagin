import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import i18n from 'laravel-vue-i18n/vite';
import VueI18nPlugin from '@intlify/unplugin-vue-i18n/vite';
import vue from '@vitejs/plugin-vue'
import path from 'path';

export default defineConfig({
    server: {
        host: '127.0.0.1',
        port: 5173,
    },
    build: {
        manifest: true,
        outDir: 'public/build',
    },
    plugins: [
        vue(),
        VueI18nPlugin({
            // Define the path to your locale files
            include: path.resolve(__dirname, 'lang/**')
        }),
        laravel({
            input: ['resources/css/app.css', 'resources/css/thread.css', 'resources/js/app.js'],
            refresh: true,
        }),
        i18n(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),       // standard Laravel alias
            '@modules': path.resolve(__dirname, 'modules'),     // 👈 add this
        },
    },
});

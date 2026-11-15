import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'Modules/Accounting/resources/assets/sass/app.scss',
                'Modules/Accounting/resources/assets/js/app.js',
            ],
            refresh: true,
        }),
    ],
});

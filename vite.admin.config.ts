import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import Icons from 'unplugin-icons/vite';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';

/**
 * مسار داخل المشروع مبني على مجلد العمل نفسه. على Windows قد يختلف حرف القرص (c: و C:) بين
 * import.meta.url ومجلد العمل، فيُعامَل الملف نفسه كوحدتين وتُضمَّن الحزم مرتين.
 */
const root = (path: string): string => resolve(process.cwd(), path);

/**
 * لوحة التحكم (Inertia + Vue + Tailwind 4)، تُبنى في public/build-admin.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            buildDirectory: 'build-admin',
            hotFile: 'public/admin.hot',
            refresh: ['Modules/**/routes/**', 'Modules/**/Lang/**', 'routes/**', 'resources/views/app.blade.php'],
        }),
        vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
        tailwindcss(),
        Icons({ compiler: 'vue3' }),
    ],
    resolve: {
        alias: [
            { find: '@modules', replacement: root('Modules') },
            { find: '@', replacement: root('resources/js') },
        ],
        // نسخة واحدة من Vue و Inertia: نسختان تعنيان ان usePage() في التخطيط يقرأ صفحة لم تُضبط (صفحة بيضاء).
        dedupe: ['vue', '@inertiajs/vue3', '@inertiajs/core'],
    },
    server: {
        host: '127.0.0.1',
        port: 5174,
        watch: { ignored: ['**/storage/**', '**/vendor/**', '**/node_modules/**'] },
    },
});

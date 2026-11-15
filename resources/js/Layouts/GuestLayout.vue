<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { Store } from '@/lib/icons';
import { useLocales } from '@/lib/useLocales';

const page = usePage();
const name = computed(() => (page.props.store as { name: string } | null)?.name ?? '');
/** Guest pages switch language with ?lang= (App\Support\Locales::forPage). */
const { locales, current, urlFor } = useLocales();
</script>

<template>
    <main class="grid min-h-screen bg-white lg:grid-cols-2 dark:bg-gray-950">
        <div class="flex flex-col px-4 py-6">
            <nav v-if="locales.length > 1" class="flex justify-end gap-1" :aria-label="current?.native">
                <a
                    v-for="locale in locales"
                    :key="locale.code"
                    :href="urlFor(locale.code)"
                    :hreflang="locale.code"
                    :lang="locale.code"
                    class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm"
                    :class="locale.code === current?.code ? 'bg-surface font-medium text-ink' : 'text-muted hover:text-ink'"
                    :aria-current="locale.code === current?.code ? 'true' : undefined"
                >
                    <img :src="`/images/flags/${locale.flag}.svg`" alt="" class="size-4 rounded-full object-cover" />
                    {{ locale.native }}
                </a>
            </nav>
            <div class="flex flex-1 items-center justify-center py-6">
                <div class="w-full max-w-md"><slot /></div>
            </div>
        </div>
        <!-- The brand side: the panel color, a fading grid, the site's name. -->
        <div class="relative m-3 hidden items-center justify-center overflow-hidden rounded-2xl bg-brand-600 lg:flex">
            <div
                class="absolute inset-0 opacity-20 [background-image:linear-gradient(white_1px,transparent_1px),linear-gradient(90deg,white_1px,transparent_1px)] [background-size:44px_44px] [mask-image:radial-gradient(ellipse_at_center,black,transparent_70%)]"
                aria-hidden="true"
            />
            <div class="relative flex flex-col items-center gap-5 text-center">
                <span class="flex size-16 items-center justify-center rounded-2xl bg-white/15 text-white ring-1 ring-white/25 backdrop-blur"><Store class="size-8" /></span>
                <p class="text-3xl font-semibold text-white">{{ name }}</p>
            </div>
        </div>
    </main>
</template>

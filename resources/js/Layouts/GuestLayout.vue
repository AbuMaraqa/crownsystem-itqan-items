<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { t } from '@/lib/i18n';
import { Store } from '@/lib/icons';
import { useLocales } from '@/lib/useLocales';

const page = usePage();
const name = computed(() => (page.props.store as { name: string } | null)?.name ?? '');
/** Guest pages switch language with ?lang= (App\Support\Locales::forPage). */
const { locales, current, urlFor } = useLocales();
</script>

<!--
    The sign-in screens: the form in a narrow card on one half, the brand panel on the other.
    On a phone the brand panel shrinks to a strip above the form.
-->
<template>
    <main class="grid min-h-screen grow bg-canvas text-ink lg:grid-cols-2">
        <div class="order-2 flex flex-col p-6 lg:order-1 lg:p-10">
            <nav v-if="locales.length > 1" class="flex justify-end gap-1" :aria-label="t('Language')">
                <a
                    v-for="locale in locales"
                    :key="locale.code"
                    :href="urlFor(locale.code)"
                    :hreflang="locale.code"
                    :lang="locale.code"
                    class="flex h-control-sm items-center gap-1.5 rounded-control px-2.5 text-[13px]"
                    :class="locale.code === current?.code ? 'bg-gray-100/70 font-medium text-ink dark:bg-white/5' : 'text-muted hover:text-ink'"
                    :aria-current="locale.code === current?.code ? 'true' : undefined"
                >
                    <img :src="`/images/flags/${locale.flag}.svg`" alt="" class="size-4 rounded-full object-cover" />
                    {{ locale.native }}
                </a>
            </nav>
            <div class="flex flex-1 items-center justify-center py-8">
                <div class="w-full max-w-[370px] rounded-xl border border-line bg-card p-8 shadow-card sm:p-10">
                    <div class="flex flex-col gap-5"><slot /></div>
                </div>
            </div>
        </div>

        <!-- The brand panel: the panel color's light tint, a fading grid and an outline of the app (decoration only). -->
        <section class="relative order-1 overflow-hidden border-b border-line bg-brand-25 lg:order-2 lg:m-5 lg:rounded-xl lg:border dark:bg-brand-500/[0.06]">
            <div
                class="absolute inset-0 [background-image:linear-gradient(var(--color-brand-100)_1px,transparent_1px),linear-gradient(90deg,var(--color-brand-100)_1px,transparent_1px)] [background-size:40px_40px] [mask-image:radial-gradient(ellipse_at_top,black,transparent_75%)] dark:opacity-20"
                aria-hidden="true"
            />
            <div class="relative flex h-full flex-col">
                <div class="flex flex-col gap-3 p-8 lg:gap-4 lg:p-16">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-brand-500 text-white shadow-theme-sm lg:mb-3"><Store class="size-6" /></span>
                    <p class="text-sm font-medium text-brand-600 dark:text-brand-400">
                        {{ name }}
                    </p>
                    <h2 class="text-xl font-semibold text-gray-950 lg:text-2xl dark:text-white">
                        {{ t('Manage your business, all in one place') }}
                    </h2>
                    <p class="hidden max-w-md text-sm leading-6 text-gray-700 sm:block lg:text-base lg:leading-7 dark:text-gray-300">
                        {{ t('Sign in to access your account and manage your data securely and easily from one place') }}
                    </p>
                </div>

                <!-- An outline of the panel: sidebar, header, stat tiles and a table. -->
                <div class="relative hidden flex-1 lg:block" aria-hidden="true">
                    <div class="absolute start-16 top-0 -end-10 bottom-[-40px] flex overflow-hidden rounded-ss-xl border-s border-t border-line bg-card shadow-theme-lg dark:bg-gray-950">
                        <div class="flex w-40 shrink-0 flex-col gap-2.5 border-e border-line p-4">
                            <span class="mb-2 h-3 w-20 rounded-full bg-gray-200 dark:bg-gray-800" />
                            <span v-for="n in 7" :key="n" class="flex items-center gap-2">
                                <span
                                    class="size-3 rounded bg-gray-200 dark:bg-gray-800"
                                    :class="{
                                        'bg-brand-400 dark:bg-brand-500': n === 2,
                                    }"
                                />
                                <span class="h-2 rounded-full bg-gray-100 dark:bg-gray-800/70" :class="[n === 2 ? 'w-16 bg-brand-200 dark:bg-brand-500/40' : n % 3 === 0 ? 'w-12' : 'w-20']" />
                            </span>
                        </div>
                        <div class="flex min-w-0 flex-1 flex-col gap-4 p-5">
                            <div class="flex items-center justify-between">
                                <span class="h-3 w-28 rounded-full bg-gray-200 dark:bg-gray-800" />
                                <span class="flex gap-2">
                                    <span v-for="n in 3" :key="n" class="size-5 rounded-full bg-gray-100 dark:bg-gray-800" />
                                </span>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <div v-for="n in 3" :key="n" class="flex flex-col gap-2 rounded-lg border border-line p-3">
                                    <span
                                        class="size-5 rounded-md"
                                        :class="['bg-brand-100 dark:bg-brand-500/30', 'bg-success-100 dark:bg-success-500/30', 'bg-warning-100 dark:bg-warning-500/30'][n - 1]"
                                    />
                                    <span class="h-3 w-12 rounded-full bg-gray-200 dark:bg-gray-800" />
                                    <span class="h-2 w-16 rounded-full bg-gray-100 dark:bg-gray-800/70" />
                                </div>
                            </div>
                            <div class="flex flex-col rounded-lg border border-line">
                                <div v-for="n in 6" :key="n" class="flex items-center gap-3 border-b border-line px-3 py-2.5 last:border-b-0">
                                    <span class="size-5 shrink-0 rounded-full bg-gray-100 dark:bg-gray-800" />
                                    <span class="h-2 flex-1 rounded-full bg-gray-100 dark:bg-gray-800/70" />
                                    <span class="h-2 w-10 rounded-full bg-gray-100 dark:bg-gray-800/70" />
                                    <span class="h-4 w-12 rounded-md" :class="n % 2 ? 'bg-success-50 dark:bg-success-500/20' : 'bg-brand-50 dark:bg-brand-500/20'" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</template>

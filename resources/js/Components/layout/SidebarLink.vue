<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { t } from '@/lib/i18n';

import { fallbackNavIcon, navIcons, type NavEntry } from './navIcons';

defineProps<{ entry: NavEntry; href: string; active: boolean; showText: boolean }>();
</script>

<template>
    <li>
        <Link
            :href="href"
            class="group flex h-[34px] items-center gap-2.5 rounded-lg border border-transparent px-2.5 text-sm font-medium transition-colors"
            :class="[
                active ? 'bg-gray-100/60 text-brand-500 dark:bg-white/5 dark:text-brand-400' : 'text-gray-950 hover:bg-gray-100/60 hover:text-brand-500 dark:text-gray-200 dark:hover:bg-white/[0.03] dark:hover:text-brand-400',
                { 'justify-center': !showText },
            ]"
            :title="showText ? undefined : t(entry.title)"
            :aria-label="showText ? undefined : t(entry.title)"
            :aria-current="active ? 'page' : undefined"
        >
            <component
                :is="navIcons[entry.icon] ?? fallbackNavIcon"
                class="size-[18px] shrink-0 transition-colors"
                :class="active ? 'text-brand-500 dark:text-brand-400' : 'text-gray-500 group-hover:text-brand-500 dark:text-gray-400 dark:group-hover:text-brand-400'"
            />
            <span v-if="showText" class="truncate">{{ t(entry.title) }}</span>
        </Link>
    </li>
</template>

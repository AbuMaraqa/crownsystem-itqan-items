<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * One row action as a coloured icon with a tooltip (the buttons of `RecordActions`). The icon goes in
 * the default slot. `href` makes it a link (`external` for a PDF or a download).
 */
const props = withDefaults(
    defineProps<{
        label: string;
        tone?: 'info' | 'view' | 'edit' | 'delete' | 'neutral';
        href?: string;
        external?: boolean;
    }>(),
    { tone: 'neutral', href: undefined, external: false },
);

defineEmits<{ click: [] }>();

const tones: Record<NonNullable<typeof props.tone>, string> = {
    info: 'bg-orange-100 text-orange-500 hover:bg-orange-200 dark:bg-orange-500/15 dark:text-orange-400 dark:hover:bg-orange-500/25',
    view: 'bg-gray-100 text-gray-500 hover:bg-gray-200 hover:text-gray-700 dark:bg-white/5 dark:text-gray-400 dark:hover:bg-white/10',
    edit: 'bg-emerald-100 text-emerald-600 hover:bg-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-400 dark:hover:bg-emerald-500/25',
    delete: 'bg-red-50 text-red-500 hover:bg-red-100 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20',
    neutral: 'bg-brand-50 text-brand-600 hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-400 dark:hover:bg-brand-500/20',
};

const classes = 'inline-flex size-8 items-center justify-center rounded-lg transition-colors outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 [&_svg]:size-[18px]';
</script>

<template>
    <span class="group/action relative inline-flex">
        <a v-if="href && external" :href="href" target="_blank" rel="noopener" :class="[classes, tones[tone]]" :aria-label="label"><slot /></a>
        <Link v-else-if="href" :href="href" :class="[classes, tones[tone]]" :aria-label="label"><slot /></Link>
        <button v-else type="button" :class="[classes, tones[tone]]" :aria-label="label" @click="$emit('click')"><slot /></button>
        <span
            role="tooltip"
            class="pointer-events-none absolute start-1/2 bottom-full z-20 mb-1.5 rounded-md bg-gray-900/90 px-2 py-1 text-xs font-medium whitespace-nowrap text-white opacity-0 shadow-theme-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 ltr:-translate-x-1/2 rtl:translate-x-1/2 dark:bg-gray-700"
        >
            {{ label }}
        </span>
    </span>
</template>

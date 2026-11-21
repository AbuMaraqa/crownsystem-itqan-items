<script setup lang="ts">
import { CircleAlert, CircleCheck, Info } from '@/lib/icons';

/** A message box inside a page or form: a sent link, a saved change, a warning. */
withDefaults(defineProps<{ tone?: 'info' | 'success' | 'warning' | 'danger' }>(), { tone: 'info' });

const tones = {
    info: 'border-brand-200 bg-brand-50 text-brand-700 dark:border-brand-500/25 dark:bg-brand-500/10 dark:text-brand-300',
    success: 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/25 dark:bg-success-500/10 dark:text-success-400',
    warning: 'border-warning-200 bg-warning-50 text-warning-800 dark:border-warning-500/25 dark:bg-warning-500/10 dark:text-warning-400',
    danger: 'border-error-200 bg-error-50 text-error-700 dark:border-error-500/25 dark:bg-error-500/10 dark:text-error-400',
} as const;
</script>

<template>
    <div class="flex items-start gap-2.5 rounded-lg border px-3.5 py-3 text-[13px] leading-5" :class="tones[tone]" :role="tone === 'danger' ? 'alert' : 'status'">
        <CircleCheck v-if="tone === 'success'" class="mt-px size-4 shrink-0" />
        <CircleAlert v-else-if="tone === 'danger' || tone === 'warning'" class="mt-px size-4 shrink-0" />
        <Info v-else class="mt-px size-4 shrink-0" />
        <div class="min-w-0"><slot /></div>
    </div>
</template>

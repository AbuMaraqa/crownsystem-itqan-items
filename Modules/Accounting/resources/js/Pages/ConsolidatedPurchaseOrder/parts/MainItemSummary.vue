<script setup lang="ts">
import { t } from '@/lib/i18n';

import type { MainItem } from '../types';

/**
 * The head of a main item: its number, name, code and barcode, and for a weight item its total
 * weight (sum of its sub-items) and actual weight (from the scale).
 */
defineProps<{ main: MainItem; number: number }>();

const quantity = (value: number | null): string => (value === null ? '—' : value.toLocaleString('en', { maximumFractionDigits: 3 }));
</script>

<template>
    <div class="flex min-w-0 flex-1 items-start gap-2">
        <span class="mt-0.5 inline-flex h-6 min-w-6 shrink-0 items-center justify-center rounded-md bg-brand-500 px-1.5 text-xs font-bold text-white tabular-nums">{{ number }}</span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                <span class="font-semibold text-gray-900 dark:text-white">{{ main.name }}</span>
                <span v-if="main.code" class="text-xs text-muted">{{ t('Code') }}: <span class="font-mono" dir="ltr">{{ main.code }}</span></span>
                <span v-if="main.barcode" class="font-mono text-xs text-muted" dir="ltr">{{ main.barcode }}</span>
            </div>
            <div v-if="main.is_weight_priced" class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted">
                <span>{{ t('Total Weight') }}: <span class="font-mono font-semibold tabular-nums text-ink" dir="ltr">{{ quantity(main.total_weight ?? 0) }}</span></span>
                <span>{{ t('Actual Weight') }}: <span class="font-mono font-semibold tabular-nums text-ink" dir="ltr">{{ quantity(main.actual_weight) }}</span></span>
            </div>
        </div>
    </div>
</template>

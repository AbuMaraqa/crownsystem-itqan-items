<script setup lang="ts">
import { t } from '@/lib/i18n';
import { CircleAlert, CircleCheck } from '@/lib/icons';

import { toneText, type AccountSummary } from './types';

defineProps<{
    summary: AccountSummary;
    /** The class the tree is narrowed to. */
    selected: number | null;
}>();

const emit = defineEmits<{ select: [value: number | null] }>();

// The accounting equation, in the order of the classes: Assets = Liabilities + Equity + Revenues − Expenses.
const operators = ['=', '+', '+', '−'];
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-line bg-card shadow-card" :aria-label="t('Account classes')">
        <!-- 1px gaps over the line color draw the dividers at every width. -->
        <div class="grid grid-cols-2 gap-px bg-line sm:grid-cols-3 lg:grid-cols-5">
            <button
                v-for="(item, index) in summary.classes"
                :key="item.value"
                type="button"
                class="relative flex flex-col gap-3 p-4 text-start transition-colors outline-none max-sm:last:col-span-2 focus-visible:bg-surface sm:max-lg:last:col-span-2 lg:p-5"
                :class="selected === item.value ? 'bg-surface' : 'bg-card hover:bg-gray-50 dark:hover:bg-white/[0.03]'"
                :aria-pressed="selected === item.value"
                :title="t('Filter by this class')"
                @click="emit('select', selected === item.value ? null : item.value)"
            >
                <span
                    v-if="index > 0"
                    class="absolute top-1/2 -start-3 z-10 hidden size-6 -translate-y-1/2 items-center justify-center rounded-full border border-line bg-card text-sm font-semibold text-muted lg:inline-flex"
                    aria-hidden="true"
                    >{{ operators[index - 1] }}</span
                >

                <span class="flex items-center gap-2">
                    <span class="h-4 w-1 rounded-full bg-current" :class="toneText[item.tone]" aria-hidden="true" />
                    <span class="text-sm font-semibold text-ink">{{ item.label }}</span>
                    <span class="ms-auto text-xs text-muted tabular-nums">{{ t(':count accounts', { count: item.count }) }}</span>
                </span>

                <span class="flex items-baseline gap-2">
                    <bdi dir="ltr" class="text-xl font-semibold tracking-tight text-ink tabular-nums lg:text-2xl" :class="{ 'text-muted!': !item.balance_direction }">{{ item.balance }}</bdi>
                    <span v-if="item.balance_direction" class="text-xs font-medium" :class="toneText[item.balance_direction.tone]">{{ item.balance_direction.label }}</span>
                </span>

                <span class="absolute inset-x-0 bottom-0 h-0.5 bg-current transition-opacity" :class="[toneText[item.tone], selected === item.value ? 'opacity-100' : 'opacity-0']" aria-hidden="true" />
            </button>
        </div>

        <p
            class="flex items-center gap-2 border-t border-line px-4 py-2.5 text-xs lg:px-5"
            :class="summary.difference ? 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400' : 'text-muted'"
            role="status"
        >
            <CircleAlert v-if="summary.difference" class="size-4 shrink-0" />
            <CircleCheck v-else class="size-4 shrink-0 text-success-600 dark:text-success-400" />
            <span v-if="summary.difference">{{ t('Debits and credits differ by :amount', { amount: summary.difference }) }}</span>
            <span v-else>{{ t('Total debits equal total credits') }}</span>
        </p>
    </section>
</template>

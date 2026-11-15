<script setup lang="ts">
import { computed } from 'vue';

import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

import type { CostSummary } from '../types';

/**
 * The order's cost as the server computes it on save (ProductionCost): items + wages + services,
 * unit cost = total ÷ produced quantity, and the margin against the sales order's value.
 */
const props = defineProps<{ summary: CostSummary; currency: string | null; loading?: boolean }>();

const money = (value: number): string => value.toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const hasSales = computed(() => props.summary.sales_amount > 0);
</script>

<template>
    <Card :title="t('Cost Calculation')">
        <template #actions>
            <span v-if="loading" class="size-4 animate-spin rounded-full border-2 border-brand-500 border-e-transparent" aria-hidden="true" />
            <span v-if="currency" class="rounded-md bg-surface px-2 py-0.5 text-xs font-medium text-muted" dir="ltr">{{ currency }}</span>
        </template>

        <dl class="grid gap-3 sm:grid-cols-3">
            <div v-for="[label, value] in [[t('Items Cost'), summary.materials_cost], [t('Wages Cost'), summary.labor_cost], [t('Services Cost'), summary.services_cost]] as [string, number][]" :key="label" class="rounded-lg border border-line px-3 py-2">
                <dt class="text-xs text-muted">{{ label }}</dt>
                <dd class="mt-1 font-mono text-base font-semibold tabular-nums" dir="ltr">{{ money(value) }}</dd>
            </div>
        </dl>

        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 dark:border-brand-500/30 dark:bg-brand-500/10">
                <dt class="text-xs text-muted">{{ t('Total Cost') }}</dt>
                <dd class="mt-1 font-mono text-lg font-bold tabular-nums" dir="ltr">{{ money(summary.total_cost) }}</dd>
            </div>
            <div class="rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 dark:border-brand-500/30 dark:bg-brand-500/10">
                <dt class="text-xs text-muted">{{ t('Unit Cost') }}</dt>
                <dd class="mt-1 font-mono text-lg font-bold tabular-nums" dir="ltr">{{ money(summary.unit_cost) }}</dd>
            </div>
            <div class="rounded-lg border border-line px-3 py-2">
                <dt class="text-xs text-muted">{{ t('Sales Value') }}</dt>
                <dd class="mt-1 font-mono text-base font-semibold tabular-nums" dir="ltr">{{ hasSales ? money(summary.sales_amount) : '-' }}</dd>
            </div>
            <div class="rounded-lg border border-line px-3 py-2">
                <dt class="text-xs text-muted">{{ t('Margin') }}</dt>
                <dd class="mt-1 font-mono text-base font-semibold tabular-nums" :class="hasSales ? (summary.margin < 0 ? 'text-danger' : 'text-success') : ''" dir="ltr">
                    <template v-if="hasSales">
                        {{ money(summary.margin) }}
                        <span v-if="summary.margin_rate !== null" class="text-xs font-medium">({{ summary.margin_rate.toFixed(2) }}%)</span>
                    </template>
                    <template v-else>-</template>
                </dd>
            </div>
        </dl>

        <p class="mt-3 text-xs text-muted">
            {{ t('Total cost = items + wages + services. Unit cost = total cost ÷ produced quantity. Sales value is the sales order total after discounts and before tax.') }}
        </p>
    </Card>
</template>

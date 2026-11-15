<script setup lang="ts">
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

import { money } from './useItemsGrid';
import type { DocumentTotals } from './types';

/**
 * The document's totals beside the grid. The figures come from the grid (the server's
 * ItemsGridTotals while typing); the saving service computes them again. The document discount
 * field belongs to the page, in the `discount` slot.
 */
defineProps<{ totals: DocumentTotals; currency?: string | null; discountLabel?: string }>();
</script>

<template>
    <Card :title="t('Totals')">
        <dl class="space-y-3 text-sm">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted">{{ t('Subtotal') }}</dt>
                <dd class="tabular-nums font-medium" dir="ltr">{{ money(totals.sub_total) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted">{{ t('Items Discount') }}</dt>
                <dd class="tabular-nums font-medium" dir="ltr">{{ money(totals.items_discount) }}</dd>
            </div>
            <div v-if="$slots.discount">
                <slot name="discount" />
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted">{{ t('Tax Amount') }}</dt>
                <dd class="tabular-nums font-medium" dir="ltr">{{ money(totals.tax_amount) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2.5 dark:border-brand-500/25 dark:bg-brand-500/10">
                <dt class="font-semibold text-gray-900 dark:text-white">{{ t('Total Amount') }}</dt>
                <dd class="tabular-nums text-base font-bold text-brand-700 dark:text-brand-300" dir="ltr">{{ money(totals.total_amount) }} <span v-if="currency" class="text-xs font-medium">{{ currency }}</span></dd>
            </div>
        </dl>
    </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue';

import { controlClasses } from '@/Components/form/controlClasses';
import { t } from '@/lib/i18n';

import type { OrderItemChoice } from '../types';
import type { PickedItem } from './orderItems';

/**
 * The order's items a record belongs to (an additional cost, a reappraisal), each with a note
 * saying why. Every item of the order is listed: the tick decides; "select all" follows the ticks.
 */
const props = defineProps<{ label: string; choices: OrderItemChoice[] }>();

const items = defineModel<PickedItem[]>({ required: true });

const allSelected = computed(() => items.value.length > 0 && items.value.every((item) => item.selected));
const labelOf = (id: number): string => props.choices.find((choice) => choice.id === id)?.label ?? '—';

function selectAll(checked: boolean): void {
    items.value.forEach((item) => (item.selected = checked));
}
</script>

<template>
    <div v-if="items.length" class="grid gap-2">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium">{{ label }}</p>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" class="size-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-800" :checked="allSelected" @change="selectAll(($event.target as HTMLInputElement).checked)" />
                {{ t('Select All Items') }}
            </label>
        </div>
        <div class="overflow-hidden rounded-lg border border-line">
            <div v-for="item in items" :key="item.consolidated_purchase_order_item_id" class="grid items-center gap-2 border-b border-line px-3 py-2 last:border-0 sm:grid-cols-[auto_1fr_1fr]">
                <input v-model="item.selected" type="checkbox" class="size-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-800" :aria-label="`${t('Select')} ${labelOf(item.consolidated_purchase_order_item_id)}`" />
                <span class="text-sm">{{ labelOf(item.consolidated_purchase_order_item_id) }}</span>
                <input v-model="item.notes" :placeholder="t('Optional')" :aria-label="t('Notes')" :class="[controlClasses, 'h-control']" />
            </div>
        </div>
    </div>
</template>

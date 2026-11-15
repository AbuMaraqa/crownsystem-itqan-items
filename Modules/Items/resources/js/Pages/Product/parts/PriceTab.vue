<script setup lang="ts">
/**
 * Prices of an item or of one variation row. A weight item shows only its cost (the price per kg comes
 * from the price lists); a piece shows no price at all, its amount is worked out on its main item.
 */
import { computed } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';

import type { ErrorOf, ProductRowFields } from './types';

const props = defineProps<{ row: ProductRowFields; error: ErrorOf; weight?: boolean; piece?: boolean }>();

const discountTypes = computed(() => [
    { value: 'percentage', label: t('Percentage') },
    { value: 'fixed', label: t('Fixed') },
]);

const note = computed(() =>
    props.piece
        ? t('A piece has no price. The amount is calculated on its main item.')
        : t('This item groups its pieces. The price per kg comes from the price lists and can be changed on the document line.'),
);
</script>

<template>
    <div class="grid gap-6 md:grid-cols-2">
        <p v-if="weight" class="rounded-lg border border-blue-light-200 bg-blue-light-50 p-3 text-sm text-blue-light-700 md:col-span-2 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-400">
            {{ note }}
        </p>
        <fieldset v-if="!piece" class="grid content-start gap-4 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-semibold">{{ t('Price') }}</legend>
            <TextInput v-if="!weight" v-model="row.base_price" :label="t('Base Price')" type="number" step="any" min="0" required :error="error('base_price')" />
            <TextInput v-if="!weight" v-model="row.sale_price" :label="t('Sale Price')" type="number" step="any" min="0" :error="error('sale_price')" />
            <TextInput v-model="row.cost_price" :label="t('Cost Price')" type="number" step="any" min="0" :error="error('cost_price')" />
            <TextInput v-if="!weight" v-model="row.wholesale_price" :label="t('Wholesale Price')" type="number" step="any" min="0" :error="error('wholesale_price')" />
        </fieldset>
        <fieldset v-if="!weight" class="grid content-start gap-4 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-semibold">{{ t('Discount') }}</legend>
            <SelectInput v-model="row.discount_type" :label="t('Discount Type')" :placeholder="t('Select')" :options="discountTypes" :error="error('discount_type')" />
            <TextInput v-model="row.discount_value" :label="t('Discount Amount')" type="number" step="any" min="0" :error="error('discount_value')" />
            <TextInput v-model="row.discount_start_date" :label="t('Discount Start Date')" type="date" :error="error('discount_start_date')" />
            <TextInput v-model="row.discount_end_date" :label="t('Discount End Date')" type="date" :error="error('discount_end_date')" />
        </fieldset>
    </div>
</template>

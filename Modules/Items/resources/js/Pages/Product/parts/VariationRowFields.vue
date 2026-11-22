<script setup lang="ts">
/** The sub-form of one variation: its text, then its own tabs. Mounted only while the row is open. */
import { computed, ref, watch } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import RichEditor from '@/Components/form/RichEditor.vue';
import type { Option } from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import { t } from '@/lib/i18n';

import { rowTabsWithError } from './fieldTabs';
import MediaFields from './MediaFields.vue';
import PriceTab from './PriceTab.vue';
import StockLimitFields from './StockLimitFields.vue';
import type { VariationRow } from './types';

const props = defineProps<{ row: VariationRow; index: number; errors: Record<string, string | undefined>; categories: Option[]; canEditLimits: boolean }>();

const prefix = computed(() => `variations.${props.index}.`);
const error = (field: string): string | undefined => props.errors[`${prefix.value}${field}`];
const withErrors = computed(() => rowTabsWithError(props.errors, prefix.value));

const tabs = computed(() =>
    [
        { value: 'general', label: t('General') },
        { value: 'price', label: t('Price') },
        { value: 'inventory', label: t('Inventory') },
        { value: 'images', label: t('Images') },
        { value: 'categories', label: t('Categories') },
    ].map((tab) => ({ ...tab, hasError: withErrors.value.has(tab.value) })),
);

const active = ref('general');

// A row opened by an error shows the tab that holds it.
watch(
    withErrors,
    (names) => {
        const first = tabs.value.find((tab) => names.has(tab.value));
        if (first && !names.has(active.value)) active.value = first.value;
    },
    { immediate: true },
);
</script>

<template>
    <div class="grid gap-4">
        <div class="grid gap-4 md:grid-cols-2">
            <TextInput :model-value="row.name" :label="t('Name')" disabled required :error="error('name')" />
            <TextInput v-model="row.slug" :label="t('Slug')" dir="ltr" :placeholder="t('Keep empty for auto generated')" :error="error('slug')" />
            <TextareaInput v-model="row.short_description" class="md:col-span-2" :label="t('Short Description')" :error="error('short_description')" />
            <RichEditor v-model="row.description" class="md:col-span-2" :label="t('Description')" :error="error('description')" />
        </div>

        <Tabs v-model="active" :tabs="tabs">
            <template #general>
                <div class="grid gap-4 md:grid-cols-2">
                    <TextInput v-model="row.sku" :label="t('Sku')" dir="ltr" :placeholder="t('Keep empty for auto generated')" :error="error('sku')" />
                    <TextInput v-model="row.barcode" :label="t('Barcode')" dir="ltr" :error="error('barcode')" />
                    <Toggle v-model="row.is_active" :label="t('Active')" />
                </div>
            </template>
            <template #price><PriceTab :row="row" :error="error" /></template>
            <template #inventory>
                <div class="grid gap-4 md:grid-cols-3">
                    <StockLimitFields :row="row" :error="error" :editable="canEditLimits" />
                </div>
            </template>
            <template #images><MediaFields :row="row" :error="error" /></template>
            <template #categories>
                <MultiSelect v-model="row.category_id" :label="t('Category')" :options="categories" :error="error('category_id')" />
            </template>
        </Tabs>
    </div>
</template>

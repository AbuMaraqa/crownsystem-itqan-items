<script setup lang="ts">
/**
 * The item card Add and Edit share. What shows follows the item, as on the old page: a weight item
 * hides the e-commerce parts (tags, labels, brand, manufacturers, addons, variations, meta) and its
 * base price, a piece hides its price, a service hides the inventory tab and the main item.
 * A validation error marks its tab and opens it, then the first message scrolls into view.
 */
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

import Card from '@/Components/ui/Card.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import { t } from '@/lib/i18n';

import AccessoriesTab from './parts/AccessoriesTab.vue';
import AddonsTab from './parts/AddonsTab.vue';
import CuttingRecipesTab from './parts/CuttingRecipesTab.vue';
import { tabsWithError } from './parts/fieldTabs';
import GeneralInfoCard from './parts/GeneralInfoCard.vue';
import GeneralTab from './parts/GeneralTab.vue';
import InventoryTab from './parts/InventoryTab.vue';
import MediaFields from './parts/MediaFields.vue';
import PriceTab from './parts/PriceTab.vue';
import { isPiece, isService, isWeight } from './parts/productForm';
import SeoFields from './parts/SeoFields.vue';
import SubItemsTab from './parts/SubItemsTab.vue';
import type { ProductContext, ProductForm, ProductFormOptions } from './parts/types';
import VariationsTab from './parts/VariationsTab.vue';

const props = defineProps<{ form: InertiaForm<ProductForm>; options: ProductFormOptions; context: ProductContext; collapsed?: boolean }>();

const errors = computed(() => props.form.errors as Record<string, string | undefined>);
const error = (field: string): string | undefined => errors.value[field];
const withErrors = computed(() => tabsWithError(Object.keys(errors.value)));

const tabs = computed(() =>
    [
        { value: 'general', label: t('General'), shown: true },
        { value: 'sub_items', label: `${t('Sub Items')} (${props.context.subProducts.length})`, shown: props.context.subProducts.length > 0 },
        { value: 'price', label: t('Price'), shown: true },
        { value: 'inventory', label: t('Inventory'), shown: !isService(props.form) },
        { value: 'accessories', label: props.form.accessories.length ? `${t('Accessories')} (${props.form.accessories.length})` : t('Accessories'), shown: true },
        { value: 'addons', label: t('Addons'), shown: !isWeight(props.form) },
        { value: 'cutting_recipes', label: t('Cutting Recipes'), shown: true },
        { value: 'variations', label: t('Generate Variations'), shown: !isWeight(props.form) },
    ]
        .filter((tab) => tab.shown)
        .map(({ value, label }) => ({ value, label, hasError: withErrors.value.has(value) })),
);

const active = ref('general');
const root = ref<HTMLElement>();

// A tab that went away (the item became a weight item or a service) leaves the first one open.
watch(tabs, (list) => {
    if (!list.some((tab) => tab.value === active.value)) active.value = 'general';
});

watch(errors, async () => {
    const first = tabs.value.find((tab) => tab.hasError);

    if (first && !withErrors.value.has(active.value)) active.value = first.value;

    await nextTick();

    const message = [...(root.value?.querySelectorAll<HTMLElement>('[role="alert"]') ?? [])].find((element) => element.offsetParent !== null);
    message?.scrollIntoView({ block: 'center', behavior: 'smooth' });
});
</script>

<template>
    <div ref="root" class="grid items-start gap-4 lg:grid-cols-3">
        <div class="grid gap-4 lg:col-span-2">
            <GeneralInfoCard :form="form" :options="options" :category-required="context.productId !== null" />
            <Card>
                <Tabs v-model="active" :tabs="tabs">
                    <template #general><GeneralTab :form="form" :options="options" :context="context" /></template>
                    <template #sub_items><SubItemsTab :rows="context.subProducts" /></template>
                    <template #price><PriceTab :row="form" :error="error" :weight="isWeight(form)" :piece="isPiece(form)" /></template>
                    <template #inventory><InventoryTab :form="form" :options="options" :context="context" :error="error" /></template>
                    <template #accessories><AccessoriesTab :form="form" :context="context" /></template>
                    <template #addons><AddonsTab :form="form" :addons="options.addons" :collapsed="collapsed" /></template>
                    <template #cutting_recipes><CuttingRecipesTab :form="form" :context="context" /></template>
                    <template #variations><VariationsTab :form="form" :attributes="options.attributes" :categories="options.categories" :can-edit-limits="options.canEditLimits" /></template>
                </Tabs>
            </Card>
        </div>
        <div class="grid gap-4">
            <Card :title="t('Images')">
                <MediaFields :row="form" :error="error" stacked />
            </Card>
            <Card v-if="!isWeight(form)" :title="t('Meta Information')">
                <SeoFields :row="form" :error="error" />
            </Card>
        </div>
    </div>
</template>

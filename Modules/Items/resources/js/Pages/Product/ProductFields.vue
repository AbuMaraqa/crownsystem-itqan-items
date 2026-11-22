<script setup lang="ts">
/**
 * The item card Add and Edit share: the information card, then the tabs. An item belongs to its
 * categories only. A weight item's colours are its variations, each priced by the kg price of its
 * price group (category × colour); a non-weight item builds its variations from attribute values.
 * What the online store shows is gathered in the store tab. A service hides the inventory tab.
 * A validation error marks its tab and opens it, then the first message scrolls into view.
 */
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

import Card from '@/Components/ui/Card.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import { t } from '@/lib/i18n';

import AccessoriesTab from './parts/AccessoriesTab.vue';
import ColoursTab from './parts/ColoursTab.vue';
import CuttingRecipesTab from './parts/CuttingRecipesTab.vue';
import { tabsWithError } from './parts/fieldTabs';
import GeneralInfoCard from './parts/GeneralInfoCard.vue';
import GeneralTab from './parts/GeneralTab.vue';
import InventoryTab from './parts/InventoryTab.vue';
import KgPriceTable from './parts/KgPriceTable.vue';
import PriceTab from './parts/PriceTab.vue';
import { isService, isWeight } from './parts/productForm';
import StoreTab from './parts/StoreTab.vue';
import type { ProductContext, ProductForm, ProductFormOptions } from './parts/types';
import { useCategoryColours } from './parts/useCategoryColours';
import VariationsTab from './parts/VariationsTab.vue';

const props = defineProps<{ form: InertiaForm<ProductForm>; options: ProductFormOptions; context: ProductContext; collapsed?: boolean }>();

const errors = computed(() => props.form.errors as Record<string, string | undefined>);
const error = (field: string): string | undefined => errors.value[field];
const withErrors = computed(() => tabsWithError(Object.keys(errors.value)));

const { catalogue, loading, failed } = useCategoryColours(props.form);

const counted = (label: string, count: number): string => (count ? `${label} (${count})` : label);

const tabs = computed(() =>
    [
        { value: 'general', label: t('General'), shown: true },
        { value: 'variations', label: counted(isWeight(props.form) ? t('Colours') : t('Variations'), props.form.variations.length), shown: !isService(props.form) },
        { value: 'price', label: t('Price'), shown: true },
        { value: 'inventory', label: t('Inventory'), shown: !isService(props.form) },
        { value: 'accessories', label: counted(t('Accessories'), props.form.accessories.length), shown: true },
        { value: 'cutting_recipes', label: t('Cutting Recipes'), shown: true },
        { value: 'store', label: t('Online Store'), shown: true },
    ]
        .filter((tab) => tab.shown)
        .map(({ value, label }) => ({ value, label, hasError: withErrors.value.has(value) })),
);

const active = ref('general');
const root = ref<HTMLElement>();

// A tab that went away (the item became a service) leaves the first one open.
watch(tabs, (list) => {
    if (!list.some((tab) => tab.value === active.value)) active.value = 'general';
});

// A weight item's rows are colours, a simple item's are attribute combinations: switching the pricing
// type puts the current rows aside and brings back the other kind, so switching back loses nothing.
const aside: Record<'weight' | 'simple', Pick<ProductForm, 'variations' | 'attributes'>> = {
    weight: { variations: [], attributes: {} },
    simple: { variations: [], attributes: {} },
};

watch(
    () => isWeight(props.form),
    (weight) => {
        aside[weight ? 'simple' : 'weight'] = { variations: props.form.variations, attributes: props.form.attributes };
        ({ variations: props.form.variations, attributes: props.form.attributes } = aside[weight ? 'weight' : 'simple']);
    },
);

watch(errors, async () => {
    const first = tabs.value.find((tab) => tab.hasError);

    if (first && !withErrors.value.has(active.value)) active.value = first.value;

    await nextTick();

    const message = [...(root.value?.querySelectorAll<HTMLElement>('[role="alert"]') ?? [])].find((element) => element.offsetParent !== null);
    message?.scrollIntoView({ block: 'center', behavior: 'smooth' });
});
</script>

<template>
    <div ref="root" class="grid gap-4">
        <GeneralInfoCard :form="form" :options="options" :category-required="context.productId !== null" />
        <Card>
            <Tabs v-model="active" :tabs="tabs">
                <template #general><GeneralTab :form="form" :options="options" /></template>
                <template #variations>
                    <ColoursTab v-if="isWeight(form)" :form="form" :catalogue="catalogue" :loading="loading" :failed="failed" :stock="context.variantStock" />
                    <VariationsTab v-else :form="form" :attributes="options.attributes" :categories="options.categories" :can-edit-limits="options.canEditLimits" />
                </template>
                <template #price>
                    <KgPriceTable v-if="isWeight(form)" :catalogue="catalogue" :rows="form.variations" :category-ids="form.category_id" />
                    <PriceTab v-else :row="form" :error="error" />
                </template>
                <template #inventory><InventoryTab :form="form" :options="options" :context="context" :error="error" /></template>
                <template #accessories><AccessoriesTab :form="form" :context="context" /></template>
                <template #cutting_recipes><CuttingRecipesTab :form="form" :context="context" /></template>
                <template #store><StoreTab :form="form" :options="options" :error="error" :collapsed="collapsed" /></template>
            </Tabs>
        </Card>
    </div>
</template>

<script setup lang="ts">
/**
 * Cutting recipes: the finished items that can be cut from this item and how many pieces each cut
 * yields. Picking an item fills the count: this item's length ÷ the picked item's length, rounded down.
 */
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import Repeater from '@/Components/form/Repeater.vue';
import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { CuttingRecipeRow, LookupOption, ProductContext, ProductForm } from './types';

const props = defineProps<{ form: InertiaForm<ProductForm>; context: ProductContext }>();

const errors = computed(() => props.form.errors as Record<string, string | undefined>);

const url = computed(() => route(props.context.lookupRoute, { type: 'any' }));

const picked = reactive<Record<string, string>>({});

const labelOf = (id: CuttingRecipeRow['output_product_id']): string | undefined => (id ? (picked[String(id)] ?? props.context.labels[String(id)]) : undefined);

const initial = (row: CuttingRecipeRow): LookupOption[] => {
    const label = labelOf(row.output_product_id);

    return row.output_product_id && label ? [{ value: Number(row.output_product_id), label }] : [];
};

const newRow = (): CuttingRecipeRow => ({ output_product_id: null, pieces_per_unit: 0 });

function pick(row: CuttingRecipeRow, option: LookupOption): void {
    picked[String(option.value)] = option.label;

    const source = Number(props.form.length);
    const piece = Number(option.length);

    if (source > 0 && piece > 0) row.pieces_per_unit = Math.floor(source / piece).toFixed(4);
}
</script>

<template>
    <div class="grid gap-4">
        <div v-if="context.productId">
            <p class="mb-1 text-sm font-medium">{{ t('Made from') }}</p>
            <ul v-if="context.madeFrom.length" class="list-disc space-y-1 ps-5 text-sm">
                <li v-for="(recipe, index) in context.madeFrom" :key="index">{{ recipe.label }} — {{ t('yields :count pcs', { count: recipe.yield }) }}</li>
            </ul>
            <p v-else class="text-sm text-muted">{{ t('This item is not produced by cutting another item.') }}</p>
        </div>

        <Repeater
            v-model="form.cutting_recipes"
            name="cutting_recipes"
            :errors="errors"
            :new-item="newRow"
            :label="t('Products cuttable from this item')"
            :add-label="t('Add')"
            :empty-text="t('Define which finished products can be cut from this item, and how many pieces each cut yields.')"
            :item-label="(row) => labelOf(row.output_product_id) ?? t('New Item')"
            :reorderable="false"
            collapsible
        >
            <template #item="{ item, error }">
                <div class="grid gap-4 md:grid-cols-3">
                    <RemoteSelect
                        v-model="item.output_product_id"
                        class="md:col-span-2"
                        :url="url"
                        :initial="initial(item)"
                        :label="t('Item')"
                        required
                        :error="error('output_product_id')"
                        @picked="(option) => pick(item, option)"
                    />
                    <TextInput v-model="item.pieces_per_unit" :label="t('Pieces per Unit')" type="number" step="0.0001" min="0" required :error="error('pieces_per_unit')" />
                </div>
            </template>
        </Repeater>
    </div>
</template>

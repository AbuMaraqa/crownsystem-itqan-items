<script setup lang="ts">
/**
 * Accessories: stock items suggested with this item when selling it (a suggestion only), each with a
 * suggested quantity per unit of the item. Saved by ProductAccessoryService.
 */
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import Repeater from '@/Components/form/Repeater.vue';
import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { AccessoryRow, LookupOption, ProductContext, ProductForm } from './types';

const props = defineProps<{ form: InertiaForm<ProductForm>; context: ProductContext }>();

const errors = computed(() => props.form.errors as Record<string, string | undefined>);

/** Accessories are goods: a service has its own section on the sales order. */
const url = computed(() => route(props.context.lookupRoute, { type: 'goods', ...(props.context.productId ? { exclude: props.context.productId } : {}) }));

/** Labels of the items picked in this session, beside the saved ones. */
const picked = reactive<Record<string, string>>({});

const labelOf = (id: AccessoryRow['accessory_product_id']): string | undefined => (id ? (picked[String(id)] ?? props.context.labels[String(id)]) : undefined);

const initial = (row: AccessoryRow): LookupOption[] => {
    const label = labelOf(row.accessory_product_id);

    return row.accessory_product_id && label ? [{ value: Number(row.accessory_product_id), label }] : [];
};

const newRow = (): AccessoryRow => ({ accessory_product_id: null, quantity: 1 });
</script>

<template>
    <Repeater
        v-model="form.accessories"
        name="accessories"
        :errors="errors"
        :new-item="newRow"
        :add-label="t('Add Accessory')"
        :empty-text="t('Items suggested with this item when selling it. They are suggestions only; the salesperson chooses whether to add them.')"
        :item-label="(row) => labelOf(row.accessory_product_id) ?? t('New Accessory')"
        collapsible
    >
        <template #item="{ item, error }">
            <div class="grid gap-4 md:grid-cols-3">
                <RemoteSelect
                    v-model="item.accessory_product_id"
                    class="md:col-span-2"
                    :url="url"
                    :initial="initial(item)"
                    :label="t('Item')"
                    required
                    :error="error('accessory_product_id')"
                    @picked="(option) => (picked[String(option.value)] = option.label)"
                />
                <TextInput v-model="item.quantity" :label="t('Suggested Quantity')" type="number" step="0.0001" min="0.0001" required :hint="t('Per one unit of the item.')" :error="error('quantity')" />
            </div>
        </template>
    </Repeater>
</template>

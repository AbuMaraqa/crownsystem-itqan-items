<script setup lang="ts">
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';
import { Info, Plus, Trash2 } from '@/lib/icons';

import { createGridApi } from './api';
import { iconButton } from './classes';
import type { CustomerMaterial, GridContext } from './types';

/**
 * Materials the customer brought to receive a service on them (ore, profiles to paint…). They stay
 * the customer's property: no value, no stock, no accounts. Their weight suggests the quantity of a
 * per-kg service (ServiceLines.vue `documentWeight`). The order's service saves them as they are.
 *
 *   <CustomerMaterials v-model:lines="form.customer_materials" document="sales-order" :editable :context="gridContext" />
 */
const props = defineProps<{ document: string; editable: boolean; context: GridContext; errors?: Record<string, string> }>();

const lines = defineModel<CustomerMaterial[]>('lines', { required: true });

const api = createGridApi(props.document, () => props.context);
const today = (): string => new Date().toISOString().slice(0, 10);
const uid = (): string => 'material-' + Math.random().toString(36).slice(2, 11);

function add(): void {
    lines.value.push({ _key: uid(), product_id: null, product_label: '', description: null, quantity: '0', weight: '0', received_date: today(), notes: null });
}

function remove(index: number): void {
    lines.value.splice(index, 1);
}

/** The item's name fills an empty description. */
function chooseItem(line: CustomerMaterial, option: { value: string | number; label: string }): void {
    line.product_label = option.label;

    if (!line.description) line.description = option.label;
}

const errorOf = (index: number, field: string): string | undefined => props.errors?.[`customer_materials.${index}.${field}`];
</script>

<template>
    <div class="space-y-3">
        <p class="flex items-start gap-2 rounded-lg border border-blue-light-200 bg-blue-light-50 px-3 py-2 text-sm text-blue-light-800 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-300">
            <Info class="mt-0.5 size-4 shrink-0" />
            {{ t("Materials the customer brought to receive a service on them. They remain the customer's property: they have no value in the order and do not enter inventory or the accounts.") }}
        </p>

        <p v-if="lines.length === 0" class="rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-muted">{{ t('No customer materials added yet.') }}</p>

        <div v-for="(line, index) in lines" :key="line._key" class="rounded-xl border border-line bg-surface/40 p-4 dark:bg-white/[0.02]">
            <div class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-4">
                    <RemoteSelect
                        v-if="editable"
                        :model-value="line.product_id"
                        :url="api.goodsUrl()"
                        :initial="line.product_id ? [{ value: line.product_id, label: line.product_label }] : []"
                        :label="t('Item')"
                        :placeholder="t('Select')"
                        :error="errorOf(index, 'product_id')"
                        @update:model-value="line.product_id = $event === null || $event === undefined || $event === '' ? null : Number($event)"
                        @picked="chooseItem(line, $event)"
                    />
                    <TextInput v-else :model-value="line.product_label || '-'" :label="t('Item')" disabled />
                </div>
                <div class="md:col-span-3">
                    <TextInput v-model="line.description" :label="t('Description')" :required="!line.product_id" :disabled="!editable" :error="errorOf(index, 'description')" />
                </div>
                <div class="md:col-span-1">
                    <TextInput v-model="line.quantity" :label="t('Quantity')" dir="ltr" :disabled="!editable" :error="errorOf(index, 'quantity')" />
                </div>
                <div class="md:col-span-2">
                    <TextInput v-model="line.weight" :label="`${t('Weight')} (${t('kg')})`" dir="ltr" :disabled="!editable" :error="errorOf(index, 'weight')" />
                </div>
                <div class="md:col-span-2">
                    <TextInput v-model="line.received_date" type="date" :label="t('Received Date')" :disabled="!editable" :error="errorOf(index, 'received_date')" />
                </div>
                <div class="md:col-span-11">
                    <TextInput v-model="line.notes" :label="t('Notes')" :disabled="!editable" />
                </div>
                <div class="flex items-end justify-end md:col-span-1">
                    <button
                        v-if="editable"
                        type="button"
                        :class="[iconButton, 'hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10']"
                        :aria-label="t('Remove')"
                        :title="t('Remove')"
                        @click="remove(index)"
                    >
                        <Trash2 />
                    </button>
                </div>
            </div>
        </div>

        <button
            v-if="editable"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-200 px-3 py-1.5 text-sm font-medium text-brand-600 transition-colors hover:bg-brand-50 dark:border-brand-500/30 dark:text-brand-400 dark:hover:bg-brand-500/10"
            @click="add"
        >
            <Plus />{{ t('Add Customer Material') }}
        </button>
    </div>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import FormActions from '@/Components/form/FormActions.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { controlClasses } from '@/Components/form/controlClasses';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { CircleAlert, Plus, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

import MainItemSummary from './parts/MainItemSummary.vue';
import PurchaseOrderPicker from './parts/PurchaseOrderPicker.vue';
import SubItemsTable from './parts/SubItemsTable.vue';
import type { PulledRow } from './types';

defineOptions({ layout: AdminLayout });

interface OrderForm {
    order_date: string | null;
    expected_delivery_date: string | null;
    items: PulledRow[];
}

const props = defineProps<{ order: OrderForm; branchClosed: boolean }>();

const form = useForm<OrderForm>(props.order);
const pickerOpen = ref(false);

const itemsError = computed(() => Object.entries(form.errors as Record<string, string>).find(([key]) => key === 'items' || key.startsWith('items.'))?.[1]);

const money = (value: unknown): string => Number(value ?? 0).toLocaleString('en', { maximumFractionDigits: 4 });
const quantity = (value: number | null): string => (value ?? 0).toLocaleString('en', { maximumFractionDigits: 3 });

/** A quantity line cannot go above what is left of its purchase order line (the server checks it again). */
function clamp(row: PulledRow): void {
    const value = Number(row.quantity);

    if (row.limit !== null && row.limit > 0 && value > row.limit) row.quantity = String(row.limit);
}
</script>

<template>
    <PageHeader
        :title="t('Add Consolidated Purchase Order')"
        :description="t('Pull items from one or more purchase orders into a single consolidated order.')"
        :parents="[{ title: t('Consolidated Purchase Orders'), href: route('accounting.consolidated-purchase-orders.index') }]"
    />

    <div v-if="branchClosed" class="mb-4 flex items-center gap-2 rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm font-medium text-warning-700 dark:border-warning-500/25 dark:bg-warning-500/10 dark:text-warning-400">
        <CircleAlert class="size-5 shrink-0" />
        {{ t('Your branch is currently closed.') }}
    </div>

    <form @submit.prevent="form.post(route('accounting.consolidated-purchase-orders.store'))">
        <div class="grid gap-4">
            <Card :title="t('Consolidated Purchase Order Details')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <TextInput v-model="form.order_date" type="date" :label="t('Order Date')" required :error="form.errors.order_date" />
                    <TextInput v-model="form.expected_delivery_date" type="date" :label="t('Expected Delivery Date')" :error="form.errors.expected_delivery_date" />
                </div>
            </Card>

            <Card :title="t('Items')">
                <template #actions>
                    <Button @click="pickerOpen = true"><Plus />{{ t('Add Items from Purchase Order') }}</Button>
                </template>

                <p v-if="!form.items.length" class="py-6 text-center text-sm text-muted">{{ t('No items added yet.') }}</p>
                <div v-else class="space-y-3">
                    <div v-for="(row, index) in form.items" :key="row.source_purchase_order_item_id" class="overflow-hidden rounded-lg border border-brand-200 dark:border-brand-500/30">
                        <div class="flex flex-wrap items-center gap-3 bg-brand-50/60 px-3 py-2 dark:bg-brand-500/10">
                            <MainItemSummary :main="row.main_item" :number="index + 1" />
                            <div class="flex flex-wrap items-center gap-4 text-sm">
                                <span class="text-xs text-muted">
                                    {{ t('Source') }}:
                                    <a v-if="row.source_purchase_order_id" :href="route('accounting.purchase-orders.edit', row.source_purchase_order_id)" target="_blank" class="text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.purchase_order_reference ?? '-' }}</a>
                                    <span v-else>{{ row.purchase_order_reference ?? '-' }}</span>
                                </span>
                                <span v-if="row.pricing_type !== 'weight'" class="flex items-center gap-1">
                                    <input
                                        v-model="row.quantity"
                                        type="number"
                                        step="0.001"
                                        min="0.001"
                                        :max="row.limit ?? undefined"
                                        dir="ltr"
                                        :title="t('Quantity')"
                                        :aria-label="t('Quantity')"
                                        :class="[controlClasses, 'h-8 w-24']"
                                        @change="clamp(row)"
                                    />
                                    <span v-if="row.limit !== null" class="text-xs whitespace-nowrap text-muted" dir="ltr">/ {{ quantity(row.limit) }}</span>
                                </span>
                                <span class="text-xs text-muted">
                                    {{ row.pricing_type === 'weight' ? t('Price per Kg') : t('Price') }}:
                                    <span class="font-mono font-semibold text-ink" dir="ltr">{{ money(row.unit_price) }}</span>
                                </span>
                                <button type="button" class="text-muted hover:text-danger" :title="t('Remove')" :aria-label="t('Remove')" @click="form.items.splice(index, 1)"><Trash2 class="size-4" /></button>
                            </div>
                        </div>
                        <SubItemsTable v-if="row.main_item.sub_items.length" :sub-items="row.main_item.sub_items" :number="index + 1" />
                    </div>
                </div>
                <p v-if="itemsError" class="mt-2 text-sm text-danger" role="alert">{{ itemsError }}</p>
            </Card>
        </div>

        <FormActions :processing="form.processing" :cancel-href="route('accounting.consolidated-purchase-orders.index')" :submit-label="t('Create Consolidated Purchase Order')" />
    </form>

    <PurchaseOrderPicker v-model:open="pickerOpen" v-model:rows="form.items" />
</template>

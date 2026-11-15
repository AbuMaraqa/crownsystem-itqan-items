<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import DropdownSeparator from '@/Components/ui/DropdownSeparator.vue';
import Modal from '@/Components/ui/Modal.vue';
import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { Inbox, Pencil, Plus, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

import MainItemSummary from '../parts/MainItemSummary.vue';
import PurchaseOrderPicker from '../parts/PurchaseOrderPicker.vue';
import SubItemsTable from '../parts/SubItemsTable.vue';
import type { ItemRow } from '../types';

/**
 * The items of the order, each a main item with its sub-items underneath, its purchase order and
 * how much of that line is left. Adding (from a purchase order), changing a quantity and deleting
 * are saved at once and the order's totals are computed again (ConsolidatedPurchaseOrderService).
 */
const props = defineProps<{ orderId: number; items: { rows: ItemRow[]; has_weight_items: boolean } }>();

const pickerOpen = ref(false);

const editing = ref<ItemRow | null>(null);
const quantityOpen = ref(false);
const quantityForm = useForm<{ quantity: string }>({ quantity: '' });

const image = ref<{ url: string; title: string } | null>(null);
const imageOpen = ref(false);

function editQuantity(row: ItemRow): void {
    editing.value = row;
    quantityForm.defaults({ quantity: row.quantity_value });
    quantityForm.reset();
    quantityForm.clearErrors();
    quantityOpen.value = true;
}

function saveQuantity(): void {
    if (!editing.value) return;

    quantityForm.put(route('accounting.consolidated-purchase-orders.items.update', { consolidatedPurchaseOrder: props.orderId, item: editing.value.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (quantityOpen.value = false),
    });
}

async function remove(row: ItemRow): Promise<void> {
    if (await confirm({ title: t('Are you sure you want to delete this item?'), description: row.description })) {
        router.delete(route('accounting.consolidated-purchase-orders.items.destroy', { consolidatedPurchaseOrder: props.orderId, item: row.id }), { preserveScroll: true, preserveState: true });
    }
}

function zoom(row: ItemRow): void {
    if (!row.image) return;

    image.value = { url: row.image, title: row.description };
    imageOpen.value = true;
}
</script>

<template>
    <Card :title="t('Items')">
        <template #actions>
            <Button size="sm" @click="pickerOpen = true"><Plus />{{ t('Add Items from Purchase Order') }}</Button>
        </template>

        <div v-if="!items.rows.length" class="flex flex-col items-center justify-center gap-2 py-10 text-center text-muted">
            <Inbox class="size-8" />
            <p class="text-sm">{{ t('No items added yet.') }}</p>
        </div>
        <div v-else class="-mx-5 -my-5 overflow-x-auto">
            <table class="w-full min-w-[60rem] text-sm">
                <thead class="bg-surface text-xs text-muted">
                    <tr>
                        <th class="w-16 px-4 py-2.5 text-start font-medium">{{ t('Image') }}</th>
                        <th class="px-4 py-2.5 text-start font-medium">{{ t('Main Item') }}</th>
                        <th class="px-4 py-2.5 text-start font-medium">{{ t('Source') }}</th>
                        <th class="px-4 py-2.5 text-end font-medium">{{ t('Quantity') }}</th>
                        <th v-if="items.has_weight_items" class="px-4 py-2.5 text-end font-medium">{{ t('Total Weight') }}</th>
                        <th v-if="items.has_weight_items" class="px-4 py-2.5 text-end font-medium">{{ t('Actual Weight') }}</th>
                        <th class="px-4 py-2.5 text-end font-medium">{{ t('Price') }}</th>
                        <th class="px-4 py-2.5 text-end font-medium">{{ t('Total') }}</th>
                        <th class="w-12 px-2 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in items.rows" :key="row.id" class="border-t border-line align-top">
                        <td class="px-4 py-3">
                            <button v-if="row.image" type="button" class="block cursor-zoom-in" :aria-label="t('Image')" @click="zoom(row)">
                                <img :src="row.image" :alt="row.description" class="size-11 rounded-lg object-cover" />
                            </button>
                            <span v-else class="block size-11 rounded-lg bg-surface" />
                        </td>
                        <td class="px-4 py-3">
                            <MainItemSummary :main="row.main" :number="index + 1" />
                            <div v-if="row.main.sub_items.length" class="mt-2 overflow-hidden rounded-md border border-brand-200 dark:border-brand-500/30">
                                <SubItemsTable :sub-items="row.main.sub_items" :number="index + 1" />
                            </div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <a v-if="row.source" :href="route('accounting.purchase-orders.edit', row.source.id)" class="text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.source.reference_no ?? '—' }}</a>
                            <span v-else class="text-muted">—</span>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <span class="font-mono tabular-nums" dir="ltr">{{ row.quantity }}</span>
                            <span v-if="row.original" class="block text-xs whitespace-nowrap text-muted">{{ row.original }}</span>
                            <span v-if="row.remaining_text" class="block text-xs whitespace-nowrap text-muted">{{ row.remaining_text }}</span>
                        </td>
                        <td v-if="items.has_weight_items" class="px-4 py-3 text-end font-mono tabular-nums" dir="ltr">{{ row.total_weight ?? '' }}</td>
                        <td v-if="items.has_weight_items" class="px-4 py-3 text-end font-mono tabular-nums" dir="ltr">{{ row.actual_weight ?? (row.is_weight ? '—' : '') }}</td>
                        <td class="px-4 py-3 text-end font-mono tabular-nums" dir="ltr">{{ row.unit_price }}</td>
                        <td class="px-4 py-3 text-end font-mono font-semibold tabular-nums" dir="ltr">{{ row.line_total }}</td>
                        <td class="px-2 py-2">
                            <RowMenu>
                                <!-- A weight item is pulled with its whole quantity: no quantity to change. -->
                                <DropdownItem v-if="!row.is_weight" @select="editQuantity(row)"><Pencil />{{ t('Edit') }}</DropdownItem>
                                <DropdownSeparator v-if="!row.is_weight" />
                                <DropdownItem destructive @select="remove(row)"><Trash2 />{{ t('Delete') }}</DropdownItem>
                            </RowMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>

    <PurchaseOrderPicker v-model:open="pickerOpen" :order-id="orderId" />

    <Modal v-model:open="quantityOpen" :title="t('Edit Quantity')" :description="editing?.description" width="max-w-md">
        <form class="grid gap-4" @submit.prevent="saveQuantity">
            <TextInput
                v-model="quantityForm.quantity"
                type="number"
                step="0.001"
                min="0.0001"
                :max="editing?.remaining ?? undefined"
                dir="ltr"
                :label="t('Quantity')"
                required
                :hint="editing?.remaining !== null && editing?.remaining !== undefined ? `${t('Remaining Quantity')}: ${editing.remaining}` : undefined"
                :error="quantityForm.errors.quantity"
            />
        </form>
        <template #footer>
            <Button @click="quantityOpen = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="quantityForm.processing" @click="saveQuantity">{{ t('Save') }}</Button>
        </template>
    </Modal>

    <Modal v-model:open="imageOpen" :title="image?.title ?? ''" width="max-w-3xl">
        <div class="flex items-center justify-center rounded-lg bg-surface p-4">
            <img v-if="image" :src="image.url" :alt="image.title" class="max-h-[70vh] max-w-full rounded-lg object-contain" />
        </div>
    </Modal>
</template>

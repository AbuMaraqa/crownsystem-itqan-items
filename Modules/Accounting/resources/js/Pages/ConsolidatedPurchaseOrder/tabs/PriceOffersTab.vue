<script setup lang="ts">
import { ref } from 'vue';

import { controlClasses } from '@/Components/form/controlClasses';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { CircleCheck } from '@/lib/icons';
import { route } from '@/lib/route';

import CreatableSelect from '../../parts/CreatableSelect.vue';
import type { Option } from '../../parts/types';
import PartyCell from '../parts/PartyCell.vue';
import RowActions from '../parts/RowActions.vue';
import TabTable from '../parts/TabTable.vue';
import { useTabForm } from '../parts/useTabForm';
import type { TabOptions, TabRecord } from '../types';

/**
 * Suppliers' price offers for the order's items: a price, discount and bonus per item. The totals
 * are computed by ConsolidatedPurchaseOrderPriceOfferService on save. An awarded offer cannot be
 * deleted until its award is cancelled.
 */
const props = defineProps<{ orderId: number; currencyId: number | null; rows: TabRecord[]; options: TabOptions; blankItems: Record<string, unknown>[] }>();

interface OfferItem {
    consolidated_purchase_order_item_id: number | null;
    description: string | null;
    quantity: string;
    unit_price: string;
    discount_rate: string;
    bonus_note: string | null;
}

interface OfferForm {
    supplier_id: number | null;
    currency_id: number | null;
    offer_date: string | null;
    notes: string | null;
    items: OfferItem[];
}

const blank = (): OfferForm => ({
    supplier_id: null,
    currency_id: props.currencyId,
    offer_date: new Date().toISOString().slice(0, 10),
    notes: null,
    items: props.blankItems.map((item) => ({ ...(item as unknown as OfferItem) })),
});

const { open, editingId, form, add, edit, submit, remove } = useTabForm<OfferForm>(() => props.orderId, 'price-offers', blank);
const party = ref<Option | null>(null);

function addRow(): void {
    party.value = null;
    add();
}

function editRow(row: TabRecord): void {
    party.value = row.party ?? null;
    edit(row.id, row.form as Partial<OfferForm>);
}

const itemError = (index: number, field: string): string | undefined => (form.errors as Record<string, string>)[`items.${index}.${field}`];
</script>

<template>
    <TabTable
        :title="t('Price Quotes')"
        :rows="rows"
        :columns="[
            { key: 'supplier', label: t('Supplier') },
            { key: 'offer_date', label: t('Offer Date') },
            { key: 'currency', label: t('Currency') },
            { key: 'total_amount', label: t('Total'), class: 'text-end' },
            { key: 'awarded', label: t('Awarded') },
            { key: 'notes', label: t('Notes') },
        ]"
        :add-label="t('Add Price Offer')"
        :can-add="blankItems.length > 0"
        :empty-text="t('No price offers have been added yet.')"
        @add="addRow"
    >
        <template #cell-supplier="{ row }"><PartyCell :user="row.supplier" /></template>
        <template #cell-offer_date="{ row }"><span dir="ltr">{{ row.offer_date }}</span></template>
        <template #cell-total_amount="{ row }"><span class="font-mono tabular-nums" dir="ltr">{{ row.total_amount }}</span></template>
        <template #cell-awarded="{ row }"><CircleCheck v-if="row.awarded" class="size-5 text-success" :aria-label="t('Awarded')" /><span v-else class="text-muted">—</span></template>
        <template #actions="{ row }"><RowActions :deletable="!row.awarded" @edit="editRow(row)" @delete="remove(row.id, t('Delete'))" /></template>
    </TabTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Price Offer')" width="max-w-5xl">
        <form class="grid gap-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-3">
                <CreatableSelect
                    :key="`offer-${editingId}-${open}`"
                    v-model="form.supplier_id"
                    :url="route('accounting.consolidated-purchase-orders.parties', { role: 'supplier' })"
                    :initial="party ? [party] : []"
                    :label="t('Supplier')"
                    required
                    :error="form.errors.supplier_id"
                    :create-url="route('accounting.consolidated-purchase-orders.parties.store')"
                    :create-title="t('Add Supplier')"
                    :create-body="{ role: 'supplier' }"
                />
                <SelectInput v-model="form.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :error="form.errors.currency_id" />
                <TextInput v-model="form.offer_date" type="date" :label="t('Offer Date')" required :error="form.errors.offer_date" />
            </div>
            <div class="overflow-x-auto rounded-lg border border-line">
                <table class="w-full min-w-[44rem] text-sm">
                    <thead class="bg-surface text-xs text-muted">
                        <tr>
                            <th class="px-3 py-2 text-start font-medium">{{ t('Description') }}</th>
                            <th class="w-28 px-3 py-2 text-end font-medium">{{ t('Quantity') }}</th>
                            <th class="w-36 px-3 py-2 text-end font-medium">{{ t('Price') }} *</th>
                            <th class="w-28 px-3 py-2 text-end font-medium">{{ t('Discount') }} %</th>
                            <th class="w-48 px-3 py-2 text-start font-medium">{{ t('Bonus') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in form.items" :key="index" class="border-t border-line align-top">
                            <td class="px-3 py-2">{{ item.description }}</td>
                            <td class="px-3 py-2 text-end font-mono tabular-nums" dir="ltr">{{ item.quantity }}</td>
                            <td class="px-3 py-2">
                                <input v-model="item.unit_price" type="number" step="0.0001" min="0" dir="ltr" :aria-label="t('Price')" :class="[controlClasses, 'h-control text-end']" />
                                <p v-if="itemError(index, 'unit_price')" class="mt-1 text-xs text-danger">{{ itemError(index, 'unit_price') }}</p>
                            </td>
                            <td class="px-3 py-2">
                                <input v-model="item.discount_rate" type="number" step="0.01" min="0" max="100" dir="ltr" :aria-label="t('Discount')" :class="[controlClasses, 'h-control text-end']" />
                            </td>
                            <td class="px-3 py-2"><input v-model="item.bonus_note" :placeholder="t('Optional')" :aria-label="t('Bonus')" :class="[controlClasses, 'h-control']" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="2" :error="form.errors.notes" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>

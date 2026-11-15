<script setup lang="ts">
import { computed } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';

import PartyCell from '../parts/PartyCell.vue';
import LookupSelect from '../parts/LookupSelect.vue';
import { pickedItems, type PickedItem } from '../parts/orderItems';
import OrderItemsPicker from '../parts/OrderItemsPicker.vue';
import RowActions from '../parts/RowActions.vue';
import TabTable from '../parts/TabTable.vue';
import { useTabForm } from '../parts/useTabForm';
import type { OrderItemChoice, TabOptions, TabRecord } from '../types';

/**
 * Additional costs of the order (bank fees, port charges...): a cost item, an amount, and the order
 * items it is charged on, each with a note saying why.
 */
const props = defineProps<{ orderId: number; currencyId: number | null; rows: TabRecord[]; options: TabOptions; orderItems: OrderItemChoice[] }>();

interface CostForm {
    additional_cost_type_id: number | null;
    currency_id: number | null;
    amount: string | null;
    details: string | null;
    notes: string | null;
    items: PickedItem[];
}

const blank = (): CostForm => ({ additional_cost_type_id: null, currency_id: props.currencyId, amount: null, details: null, notes: null, items: pickedItems(props.orderItems) });

const { open, editingId, form, add, edit, submit, remove } = useTabForm<CostForm>(() => props.orderId, 'additional-costs', blank);

function editRow(row: TabRecord): void {
    const values = row.form as Omit<CostForm, 'items'> & { items: { id: number; notes: string | null }[] };

    edit(row.id, { ...values, items: pickedItems(props.orderItems, values.items) });
}

const total = computed(() => props.rows.reduce((sum, row) => sum + Number(row.amount_value ?? 0), 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
</script>

<template>
    <TabTable
        :title="t('Additional Costs')"
        :rows="rows"
        :columns="[
            { key: 'type', label: t('Cost Item') },
            { key: 'details', label: t('Details') },
            { key: 'items', label: t('Items') },
            { key: 'amount', label: t('Amount'), class: 'text-end' },
            { key: 'currency', label: t('Currency') },
            { key: 'created_by', label: t('Added By') },
        ]"
        :add-label="t('Add Additional Cost')"
        can-add
        @add="add()"
    >
        <template #cell-type="{ row }"><span class="font-semibold">{{ row.type ?? '—' }}</span></template>
        <template #cell-details="{ row }">
            <span>{{ row.details ?? '—' }}</span>
            <span v-if="row.notes" class="mt-0.5 block text-xs text-muted">{{ row.notes }}</span>
        </template>
        <template #cell-items="{ row }">
            <div v-if="(row.items as string[]).length" class="flex flex-col items-start gap-1">
                <Badge v-for="(item, index) in row.items as string[]" :key="index">{{ item }}</Badge>
            </div>
            <span v-else class="text-muted">—</span>
        </template>
        <template #cell-amount="{ row }"><span class="font-mono tabular-nums" dir="ltr">{{ row.amount }}</span></template>
        <template #cell-created_by="{ row }"><PartyCell :user="row.created_by" /></template>
        <template #actions="{ row }"><RowActions @edit="editRow(row)" @delete="remove(row.id, t('Are you sure you want to delete this additional cost?'))" /></template>
        <template #footer>
            <tr class="border-t border-line bg-surface font-semibold">
                <td class="px-4 py-2.5" colspan="3">{{ t('Total') }}</td>
                <td class="px-4 py-2.5 text-end font-mono tabular-nums" dir="ltr">{{ total }}</td>
                <td colspan="3" />
            </tr>
        </template>
    </TabTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Additional Cost')" width="max-w-3xl">
        <form class="grid gap-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <LookupSelect v-model="form.additional_cost_type_id" type="additional-cost-type" :options="options.additionalCostTypeOptions" :label="t('Cost Item')" required :error="form.errors.additional_cost_type_id" />
                <SelectInput v-model="form.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :error="form.errors.currency_id" />
                <TextInput v-model="form.amount" type="number" step="0.0001" min="0" dir="ltr" :label="t('Amount')" required :error="form.errors.amount" />
            </div>
            <TextareaInput v-model="form.details" :label="t('Details')" :rows="2" :error="form.errors.details" />
            <OrderItemsPicker v-model="form.items" :label="t('Items')" :choices="orderItems" />
            <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="2" :error="form.errors.notes" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>

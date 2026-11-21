<script setup lang="ts">
import { ref } from 'vue';

import { controlClasses } from '@/Components/form/controlClasses';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import CreatableSelect from '../../parts/CreatableSelect.vue';
import type { Option } from '../../parts/types';
import PartyCell from '../parts/PartyCell.vue';
import RowActions from '../parts/RowActions.vue';
import TabTable from '../parts/TabTable.vue';
import { useTabForm } from '../parts/useTabForm';
import type { TabOptions, TabRecord } from '../types';

/**
 * The order's deliveries: the delivery company and an amount per cost estimation element (managed
 * in the settings), in one currency for the whole record.
 */
const props = defineProps<{ orderId: number; currencyId: number | null; rows: TabRecord[]; options: TabOptions }>();

interface CostItem {
    cost_element_id: number;
    amount: string | null;
    notes: string | null;
}

interface DeliveryForm {
    delivery_company_id: number | null;
    currency_id: number | null;
    notes: string | null;
    cost_items: CostItem[];
}

const costItems = (saved: Record<number, { amount: string | null; notes: string | null }> = {}): CostItem[] =>
    props.options.deliveryCostElements.map((element) => ({ cost_element_id: element.id, amount: saved[element.id]?.amount ?? null, notes: saved[element.id]?.notes ?? null }));

const blank = (): DeliveryForm => ({ delivery_company_id: null, currency_id: props.currencyId, notes: null, cost_items: costItems() });

const { open, editingId, form, add, edit, submit, remove } = useTabForm<DeliveryForm>(() => props.orderId, 'deliveries', blank);
const party = ref<Option | null>(null);

const elementName = (id: number): string => props.options.deliveryCostElements.find((element) => element.id === id)?.name ?? '—';

function addRow(): void {
    party.value = null;
    add();
}

function editRow(row: TabRecord): void {
    const values = row.form as { delivery_company_id: number | null; currency_id: number | null; notes: string | null; cost_items: Record<number, { amount: string | null; notes: string | null }> };

    party.value = row.party ?? null;
    edit(row.id, { ...values, cost_items: costItems(values.cost_items) });
}
</script>

<template>
    <TabTable
        :title="t('Delivery')"
        :rows="rows"
        :columns="[
            { key: 'company', label: t('Delivery Company') },
            { key: 'total', label: t('Total Cost'), class: 'text-end' },
            { key: 'currency', label: t('Currency') },
            { key: 'created_by', label: t('Added By') },
            { key: 'created_at', label: t('Date Added') },
            { key: 'notes', label: t('Notes') },
        ]"
        :add-label="t('Add Delivery')"
        can-add
        @add="addRow"
    >
        <template #cell-company="{ row }"><PartyCell :user="row.company" /></template>
        <template #cell-total="{ row }"><span class="font-mono tabular-nums" dir="ltr">{{ row.total }}</span></template>
        <template #cell-created_by="{ row }"><PartyCell :user="row.created_by" /></template>
        <template #cell-created_at="{ row }"><span dir="ltr">{{ row.created_at }}</span></template>
        <template #actions="{ row }"><RowActions @edit="editRow(row)" @delete="remove(row.id, t('Are you sure you want to delete this delivery?'))" /></template>
    </TabTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Delivery')" width="max-w-3xl">
        <form class="grid gap-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <CreatableSelect
                    :key="`delivery-${editingId}-${open}`"
                    v-model="form.delivery_company_id"
                    :url="route('accounting.consolidated-purchase-orders.parties', { role: 'delivery' })"
                    :initial="party ? [party] : []"
                    :label="t('Delivery Company')"
                    required
                    :error="form.errors.delivery_company_id"
                    :create-url="route('accounting.consolidated-purchase-orders.parties.store')"
                    :create-title="t('Delivery Company')"
                    :create-body="{ role: 'delivery' }"
                />
                <SelectInput v-model="form.currency_id" :label="t('Currency')" :options="options.currencyOptions" :placeholder="t('Select')" :error="form.errors.currency_id" />
            </div>

            <div v-if="form.cost_items.length" class="grid gap-2">
                <p class="text-sm font-medium">{{ t('Cost Estimation Elements') }}</p>
                <div class="overflow-hidden rounded-lg border border-line">
                    <div class="grid grid-cols-[1fr_8rem_1fr] gap-2 bg-surface px-3 py-2 text-xs font-medium text-muted">
                        <span>{{ t('Element') }}</span>
                        <span class="text-end">{{ t('Amount') }}</span>
                        <span>{{ t('Notes') }}</span>
                    </div>
                    <div v-for="item in form.cost_items" :key="item.cost_element_id" class="grid grid-cols-[1fr_8rem_1fr] items-center gap-2 border-t border-line px-3 py-2">
                        <span class="text-sm">{{ elementName(item.cost_element_id) }}</span>
                        <input v-model="item.amount" type="number" step="0.0001" min="0" dir="ltr" :aria-label="t('Amount')" :class="[controlClasses, 'h-control text-end']" />
                        <input v-model="item.notes" :placeholder="t('Optional')" :aria-label="t('Notes')" :class="[controlClasses, 'h-control']" />
                    </div>
                </div>
            </div>

            <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="2" :error="form.errors.notes" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>

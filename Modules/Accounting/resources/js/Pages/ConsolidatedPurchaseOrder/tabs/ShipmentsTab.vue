<script setup lang="ts">
import { ref } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { ExternalLink } from '@/lib/icons';
import { route } from '@/lib/route';

import CreatableSelect from '../../parts/CreatableSelect.vue';
import type { Option } from '../../parts/types';
import PartyCell from '../parts/PartyCell.vue';
import FileField from '../parts/FileField.vue';
import RowActions from '../parts/RowActions.vue';
import TabTable from '../parts/TabTable.vue';
import { useTabForm } from '../parts/useTabForm';
import type { FileRef, TabOptions, TabRecord } from '../types';

/** The order's shipments: shipping company, type, container (for a full container), price and files. */
const props = defineProps<{ orderId: number; currencyId: number | null; rows: TabRecord[]; options: TabOptions }>();

interface ShipmentForm {
    shipping_company_id: number | null;
    shipping_type: number | null;
    shipping_classification: number | null;
    container_number: string | null;
    invoice_number: string | null;
    shipping_terms: number | null;
    currency_id: number | null;
    price: string | null;
    net_weight: string | null;
    gross_weight: string | null;
    cbm: string | null;
    notes: string | null;
    attachment: File | null;
    remove_attachment: boolean;
}

const blank = (): ShipmentForm => ({
    shipping_company_id: null,
    shipping_type: null,
    shipping_classification: null,
    container_number: null,
    invoice_number: null,
    shipping_terms: null,
    currency_id: props.currencyId,
    price: null,
    net_weight: null,
    gross_weight: null,
    cbm: null,
    notes: null,
    attachment: null,
    remove_attachment: false,
});

const { open, editingId, form, add, edit, submit, remove } = useTabForm<ShipmentForm>(() => props.orderId, 'shipments', blank);
const editingRow = ref<TabRecord | null>(null);
const party = ref<Option | null>(null);

function addRow(): void {
    editingRow.value = null;
    party.value = null;
    add();
}

function editRow(row: TabRecord): void {
    editingRow.value = row;
    party.value = row.party ?? null;
    edit(row.id, row.form as Partial<ShipmentForm>);
}

const isContainer = (): boolean => Number(form.shipping_classification) === props.options.containerClassification;
</script>

<template>
    <TabTable
        :title="t('Shipping')"
        :rows="rows"
        :columns="[
            { key: 'company', label: t('Shipping Company') },
            { key: 'shipping_type', label: t('Shipping Type') },
            { key: 'container_number', label: t('Container Number') },
            { key: 'invoice_number', label: t('Invoice Number') },
            { key: 'created_by', label: t('Added By') },
            { key: 'currency', label: t('Currency') },
            { key: 'price', label: t('Price'), class: 'text-end' },
            { key: 'notes', label: t('Notes') },
        ]"
        :add-label="t('Add Shipment')"
        can-add
        @add="addRow"
    >
        <template #cell-company="{ row }">
            <PartyCell :user="row.company" />
            <a v-if="row.attachment" :href="(row.attachment as { url: string }).url" target="_blank" class="mt-1 inline-flex items-center gap-1 text-xs text-brand-600 hover:underline dark:text-brand-400"><ExternalLink class="size-3.5" />{{ t('Attachments') }}</a>
        </template>
        <template #cell-created_by="{ row }"><PartyCell :user="row.created_by" /></template>
        <template #cell-price="{ row }"><span class="font-mono tabular-nums" dir="ltr">{{ row.price }}</span></template>
        <template #actions="{ row }"><RowActions @edit="editRow(row)" @delete="remove(row.id, t('Are you sure you want to delete this shipment?'))" /></template>
    </TabTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Shipment')" width="max-w-3xl">
        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <CreatableSelect
                :key="`shipping-${editingId}-${open}`"
                v-model="form.shipping_company_id"
                :url="route('accounting.consolidated-purchase-orders.parties', { role: 'shipping' })"
                :initial="party ? [party] : []"
                :label="t('Shipping Company')"
                required
                :error="form.errors.shipping_company_id"
                :create-url="route('accounting.consolidated-purchase-orders.parties.store')"
                :create-title="t('Shipping Company')"
                :create-body="{ role: 'shipping' }"
            />
            <SelectInput v-model="form.shipping_type" :label="t('Shipping Type')" required :options="options.shippingTypeOptions" :placeholder="t('Select')" :error="form.errors.shipping_type" />
            <SelectInput v-model="form.shipping_classification" :label="t('Shipping Classification')" required :options="options.shippingClassificationOptions" :placeholder="t('Select')" :error="form.errors.shipping_classification" />
            <template v-if="isContainer()">
                <TextInput v-model="form.container_number" :label="t('Container Number')" required :maxlength="255" dir="ltr" :error="form.errors.container_number" />
                <TextInput v-model="form.invoice_number" :label="t('Invoice Number')" :maxlength="255" dir="ltr" :error="form.errors.invoice_number" />
            </template>
            <SelectInput v-model="form.shipping_terms" :label="t('Shipping Terms')" :options="options.shippingTermsOptions" :placeholder="t('Select')" :error="form.errors.shipping_terms" />
            <SelectInput v-model="form.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :error="form.errors.currency_id" />
            <TextInput v-model="form.price" type="number" step="0.0001" min="0" dir="ltr" :label="t('Price')" required :error="form.errors.price" />
            <TextInput v-model="form.net_weight" type="number" step="0.0001" min="0" dir="ltr" :label="t('Net Weight')" :error="form.errors.net_weight" />
            <TextInput v-model="form.gross_weight" type="number" step="0.0001" min="0" dir="ltr" :label="t('Gross Weight')" :error="form.errors.gross_weight" />
            <TextInput v-model="form.cbm" type="number" step="0.0001" min="0" dir="ltr" :label="t('CBM')" :error="form.errors.cbm" />
            <div class="sm:col-span-2">
                <FileField v-model="form.attachment" v-model:remove="form.remove_attachment" :label="t('Attachments')" :current="(editingRow?.attachment as FileRef | null) ?? null" :error="form.errors.attachment" />
            </div>
            <div class="sm:col-span-2">
                <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="2" :error="form.errors.notes" />
            </div>
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>

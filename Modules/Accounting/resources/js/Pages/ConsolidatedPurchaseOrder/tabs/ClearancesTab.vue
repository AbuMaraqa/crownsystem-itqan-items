<script setup lang="ts">
import { ref } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import CreatableSelect from '../../parts/CreatableSelect.vue';
import type { Option } from '../../parts/types';
import PartyCell from '../parts/PartyCell.vue';
import FileField from '../parts/FileField.vue';
import RowActions from '../parts/RowActions.vue';
import TabTable from '../parts/TabTable.vue';
import { useTabForm } from '../parts/useTabForm';
import type { FileRef, TabOptions, TabRecord } from '../types';

/**
 * The order's customs clearances: the clearance company, its total cost, and a copy and an
 * original of every clearance document type (managed in the settings).
 */
const props = defineProps<{ orderId: number; currencyId: number | null; rows: TabRecord[]; options: TabOptions }>();

interface ClearanceForm {
    clearance_company_id: number | null;
    total_cost: string | null;
    currency_id: number | null;
    notes: string | null;
    copies: Record<number, File>;
    originals: Record<number, File>;
    remove_copies: number[];
    remove_originals: number[];
}

const blank = (): ClearanceForm => ({ clearance_company_id: null, total_cost: null, currency_id: props.currencyId, notes: null, copies: {}, originals: {}, remove_copies: [], remove_originals: [] });

const { open, editingId, form, add, edit, submit, remove } = useTabForm<ClearanceForm>(() => props.orderId, 'clearances', blank);
const files = ref<Record<number, { copy: FileRef | null; original: FileRef | null }>>({});
const party = ref<Option | null>(null);

function addRow(): void {
    files.value = {};
    party.value = null;
    add();
}

function editRow(row: TabRecord): void {
    files.value = (row.files as typeof files.value) ?? {};
    party.value = row.party ?? null;
    edit(row.id, row.form as Partial<ClearanceForm>);
}

function setFile(kind: 'copies' | 'originals', typeId: number, file: File | null | undefined): void {
    const next = { ...form[kind] };

    if (file) next[typeId] = file;
    else delete next[typeId];

    form[kind] = next;
}

function setRemoved(kind: 'remove_copies' | 'remove_originals', typeId: number, removed: boolean): void {
    form[kind] = removed ? [...new Set([...form[kind], typeId])] : form[kind].filter((id) => id !== typeId);
}
</script>

<template>
    <TabTable
        :title="t('Customs Clearance')"
        :rows="rows"
        :columns="[
            { key: 'company', label: t('Clearance Company') },
            { key: 'total_cost', label: t('Total Clearance Cost'), class: 'text-end' },
            { key: 'currency', label: t('Currency') },
            { key: 'created_by', label: t('Added By') },
            { key: 'created_at', label: t('Date Added') },
            { key: 'notes', label: t('Notes') },
        ]"
        :add-label="t('Add Clearance')"
        can-add
        @add="addRow"
    >
        <template #cell-company="{ row }"><PartyCell :user="row.company" /></template>
        <template #cell-total_cost="{ row }"><span class="font-mono tabular-nums" dir="ltr">{{ row.total_cost ?? '—' }}</span></template>
        <template #cell-created_by="{ row }"><PartyCell :user="row.created_by" /></template>
        <template #cell-created_at="{ row }"><span dir="ltr">{{ row.created_at }}</span></template>
        <template #actions="{ row }"><RowActions @edit="editRow(row)" @delete="remove(row.id, t('Are you sure you want to delete this clearance?'))" /></template>
    </TabTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Clearance')" width="max-w-3xl">
        <form class="grid gap-4" @submit.prevent="submit">
            <CreatableSelect
                :key="`clearance-${editingId}-${open}`"
                v-model="form.clearance_company_id"
                :url="route('accounting.consolidated-purchase-orders.parties', { role: 'clearance' })"
                :initial="party ? [party] : []"
                :label="t('Clearance Company')"
                required
                :error="form.errors.clearance_company_id"
                :create-url="route('accounting.consolidated-purchase-orders.parties.store')"
                :create-title="t('Clearance Company')"
                :create-body="{ role: 'clearance' }"
            />
            <div class="grid gap-4 sm:grid-cols-2">
                <TextInput v-model="form.total_cost" type="number" step="0.0001" min="0" dir="ltr" :label="t('Total Clearance Cost')" :error="form.errors.total_cost" />
                <SelectInput v-model="form.currency_id" :label="t('Currency')" :options="options.currencyOptions" :placeholder="t('Select')" :error="form.errors.currency_id" />
            </div>

            <div v-if="options.clearanceAttachmentTypes.length" class="grid gap-2">
                <p class="text-sm font-medium">{{ t('Attachments') }}</p>
                <div class="overflow-hidden rounded-lg border border-line">
                    <div v-for="type in options.clearanceAttachmentTypes" :key="type.id" class="grid gap-3 border-b border-line p-3 last:border-0 sm:grid-cols-[10rem_1fr_1fr]">
                        <span class="text-sm font-medium">{{ type.name }}</span>
                        <FileField
                            :model-value="form.copies[type.id] ?? null"
                            :remove="form.remove_copies.includes(type.id)"
                            :label="t('Copy')"
                            :current="files[type.id]?.copy ?? null"
                            @update:model-value="(file) => setFile('copies', type.id, file)"
                            @update:remove="(removed: boolean) => setRemoved('remove_copies', type.id, removed)"
                        />
                        <FileField
                            :model-value="form.originals[type.id] ?? null"
                            :remove="form.remove_originals.includes(type.id)"
                            :label="t('Original')"
                            :current="files[type.id]?.original ?? null"
                            @update:model-value="(file) => setFile('originals', type.id, file)"
                            @update:remove="(removed: boolean) => setRemoved('remove_originals', type.id, removed)"
                        />
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

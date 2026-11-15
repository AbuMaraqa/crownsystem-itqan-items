<script setup lang="ts">
import { ref } from 'vue';

import TextareaInput from '@/Components/form/TextareaInput.vue';
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
import type { FileRef, TabRecord } from '../types';

/** The order's insurances: the insurance company, its documents and notes. */
const props = defineProps<{ orderId: number; rows: TabRecord[] }>();

interface InsuranceForm {
    insurance_company_id: number | null;
    notes: string | null;
    attachment: File | null;
    remove_attachment: boolean;
}

const { open, editingId, form, add, edit, submit, remove } = useTabForm<InsuranceForm>(() => props.orderId, 'insurances', () => ({ insurance_company_id: null, notes: null, attachment: null, remove_attachment: false }));
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
    edit(row.id, row.form as Partial<InsuranceForm>);
}
</script>

<template>
    <TabTable
        :title="t('Insurance')"
        :rows="rows"
        :columns="[
            { key: 'company', label: t('Company Name') },
            { key: 'notes', label: t('Notes') },
            { key: 'created_by', label: t('Added By') },
            { key: 'created_at', label: t('Date Added') },
        ]"
        :add-label="t('Add Insurance')"
        can-add
        @add="addRow"
    >
        <template #cell-company="{ row }">
            <PartyCell :user="row.company" />
            <a v-if="row.attachment" :href="(row.attachment as { url: string }).url" target="_blank" class="mt-1 inline-flex items-center gap-1 text-xs text-brand-600 hover:underline dark:text-brand-400"><ExternalLink class="size-3.5" />{{ t('Attachments') }}</a>
        </template>
        <template #cell-created_by="{ row }"><PartyCell :user="row.created_by" /></template>
        <template #cell-created_at="{ row }"><span dir="ltr">{{ row.created_at }}</span></template>
        <template #actions="{ row }"><RowActions @edit="editRow(row)" @delete="remove(row.id, t('Are you sure you want to delete this insurance?'))" /></template>
    </TabTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Insurance')" width="max-w-xl">
        <form class="grid gap-4" @submit.prevent="submit">
            <CreatableSelect
                :key="`insurance-${editingId}-${open}`"
                v-model="form.insurance_company_id"
                :url="route('accounting.consolidated-purchase-orders.parties', { role: 'insurance' })"
                :initial="party ? [party] : []"
                :label="t('Insurance Company')"
                required
                :error="form.errors.insurance_company_id"
                :create-url="route('accounting.consolidated-purchase-orders.parties.store')"
                :create-title="t('Insurance Company')"
                :create-body="{ role: 'insurance' }"
            />
            <FileField v-model="form.attachment" v-model:remove="form.remove_attachment" :label="t('Attachments')" :current="(editingRow?.attachment as FileRef | null) ?? null" :error="form.errors.attachment" />
            <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="2" :error="form.errors.notes" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>

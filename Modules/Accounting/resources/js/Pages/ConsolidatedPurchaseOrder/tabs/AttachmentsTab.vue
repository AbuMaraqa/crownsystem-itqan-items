<script setup lang="ts">
import { ref } from 'vue';

import TextareaInput from '@/Components/form/TextareaInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { ExternalLink } from '@/lib/icons';

import PartyCell from '../parts/PartyCell.vue';
import FileField from '../parts/FileField.vue';
import LookupSelect from '../parts/LookupSelect.vue';
import RowActions from '../parts/RowActions.vue';
import TabTable from '../parts/TabTable.vue';
import { useTabForm } from '../parts/useTabForm';
import type { FileRef, TabOptions, TabRecord } from '../types';

/** Files of the order, each under an attachment category (managed in the settings). */
const props = defineProps<{ orderId: number; rows: TabRecord[]; options: TabOptions }>();

interface AttachmentForm {
    attachment_category_id: number | null;
    notes: string | null;
    file: File | null;
}

const { open, editingId, form, add, edit, submit, remove } = useTabForm<AttachmentForm>(() => props.orderId, 'attachments', () => ({ attachment_category_id: null, notes: null, file: null }));
const current = ref<FileRef | null>(null);

function addRow(): void {
    current.value = null;
    add();
}

function editRow(row: TabRecord): void {
    current.value = (row.file as FileRef | null) ?? null;
    edit(row.id, row.form as Partial<AttachmentForm>);
}
</script>

<template>
    <TabTable
        :title="t('Attachments')"
        :rows="rows"
        :columns="[
            { key: 'category', label: t('Attachment Category') },
            { key: 'file', label: t('File') },
            { key: 'notes', label: t('Notes') },
            { key: 'created_by', label: t('Added By') },
            { key: 'created_at', label: t('Date Added') },
        ]"
        :add-label="t('Add Attachment')"
        can-add
        @add="addRow"
    >
        <template #cell-category="{ row }">
            <Badge v-if="row.category">{{ row.category }}</Badge>
            <span v-else class="text-muted">—</span>
        </template>
        <template #cell-file="{ row }">
            <a v-if="row.file" :href="(row.file as FileRef).url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-brand-600 hover:underline dark:text-brand-400" dir="ltr"><ExternalLink class="size-4" />{{ (row.file as FileRef).name }}</a>
            <span v-else class="text-muted">—</span>
        </template>
        <template #cell-created_by="{ row }"><PartyCell :user="row.created_by" /></template>
        <template #cell-created_at="{ row }"><span dir="ltr">{{ row.created_at }}</span></template>
        <template #actions="{ row }"><RowActions @edit="editRow(row)" @delete="remove(row.id, t('Are you sure you want to delete this attachment?'))" /></template>
    </TabTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Attachment')" width="max-w-xl">
        <form class="grid gap-4" @submit.prevent="submit">
            <LookupSelect v-model="form.attachment_category_id" type="attachment-category" :options="options.attachmentCategoryOptions" :label="t('Attachment Category')" :placeholder="t('Select an attachment category')" :error="form.errors.attachment_category_id" />
            <FileField v-model="form.file" :label="t('File')" :current="current" :required="!editingId" :error="form.errors.file" />
            <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="2" :error="form.errors.notes" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>

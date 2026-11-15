<script setup lang="ts">
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';

import PartyCell from '../parts/PartyCell.vue';
import RowActions from '../parts/RowActions.vue';
import TabTable from '../parts/TabTable.vue';
import { useTabForm } from '../parts/useTabForm';
import type { TabRecord } from '../types';

/** Notes on the order, with an optional reminder date (shown in the dashboard calendar). */
const props = defineProps<{ orderId: number; rows: TabRecord[] }>();

interface NoteForm {
    note: string | null;
    reminder_date: string | null;
}

const { open, editingId, form, add, edit, submit, remove } = useTabForm<NoteForm>(() => props.orderId, 'notes', () => ({ note: null, reminder_date: null }));
</script>

<template>
    <TabTable
        :title="t('Notes')"
        :rows="rows"
        :columns="[
            { key: 'note', label: t('Note') },
            { key: 'reminder_date', label: t('Reminder Date') },
            { key: 'created_by', label: t('Added By') },
            { key: 'created_at', label: t('Date Added') },
        ]"
        :add-label="t('Add Note')"
        can-add
        @add="add()"
    >
        <template #cell-note="{ row }"><p class="max-w-xl whitespace-pre-line">{{ row.note }}</p></template>
        <template #cell-reminder_date="{ row }"><span dir="ltr">{{ row.reminder_date ?? '—' }}</span></template>
        <template #cell-created_by="{ row }"><PartyCell :user="row.created_by" /></template>
        <template #cell-created_at="{ row }"><span dir="ltr">{{ row.created_at }}</span></template>
        <template #actions="{ row }"><RowActions @edit="edit(row.id, row.form as Partial<NoteForm>)" @delete="remove(row.id, t('Are you sure you want to delete this note?'))" /></template>
    </TabTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Note')" width="max-w-xl">
        <form class="grid gap-4" @submit.prevent="submit">
            <TextareaInput v-model="form.note" :label="t('Note')" :rows="3" required :error="form.errors.note" />
            <TextInput v-model="form.reminder_date" type="date" :label="t('Reminder Date')" :error="form.errors.reminder_date" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>

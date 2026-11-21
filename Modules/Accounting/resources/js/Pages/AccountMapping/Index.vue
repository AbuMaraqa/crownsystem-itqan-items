<script setup lang="ts">
import { computed, ref } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import DataTable from '@/Components/table/DataTable.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordActions from '@/Components/table/RecordActions.vue';
import { useRecordModal } from '@/lib/useRecordModal';

import type { Option } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    source_module: string;
    source_key: string;
    account_id: number | null;
    account: string | null;
}

const props = defineProps<{ table: TablePayload<Row>; modules: Option[]; accounts: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'source_module', label: t('Source Module'), sort: 'source_module' },
    { key: 'source_key', label: t('Source Key'), sort: 'source_key' },
    { key: 'account', label: t('Account') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'source_module', label: t('Source Module'), options: props.modules },
    { type: 'boolean', key: 'mapped', label: t('Account'), trueLabel: t('Mapped'), falseLabel: t('Not mapped') },
]);

const { open, form, edit, submit } = useRecordModal<{ account_id: number | null }>('accounting.account-mappings', () => ({ account_id: null }));

const editing = ref<Row | null>(null);

function editRow(row: Row): void {
    editing.value = row;
    edit(row.id, { account_id: row.account_id });
}

const title = (row: Row): string => `${row.source_module} / ${row.source_key}`;
</script>

<template>
    <PageHeader :title="t('Account Mappings')" :count="table.meta.total" />

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No data available')">
        <template #cell-source_module="{ row }">
            <bdi>{{ row.source_module }}</bdi>
        </template>
        <template #cell-source_key="{ row }">
            <bdi class="font-mono text-xs">{{ row.source_key }}</bdi>
        </template>
        <template #cell-account="{ row }">
            <button v-if="can('Accounting Mapping Update')" type="button" class="text-start hover:underline" :class="row.account ? 'font-medium text-brand-600 dark:text-brand-400' : 'text-error-600 dark:text-error-500'" @click="editRow(row)">
                {{ row.account ?? t('Not mapped') }}
            </button>
            <span v-else :class="{ 'text-error-600 dark:text-error-500': !row.account }">{{ row.account ?? t('Not mapped') }}</span>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Accounting Mapping"
                routes="accounting.account-mappings"
                :id="row.id"
                :title="title(row)"
                :fields="[
                    { label: t('Source Module'), value: row.source_module },
                    { label: t('Source Key'), value: row.source_key },
                    { label: t('Account'), value: row.account ?? t('Not mapped') },
                ]"
                :deletable="false"
                @edit="editRow(row)"
            />
        </template>
    </DataTable>

    <Modal v-model:open="open" :title="editing ? title(editing) : t('Edit')" width="max-w-lg">
        <form id="account-mapping-form" class="grid gap-4" @submit.prevent="submit">
            <MultiSelect v-model="form.account_id" single :options="accounts" :label="t('Account')" :placeholder="t('Not mapped')" :error="form.errors.account_id" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>

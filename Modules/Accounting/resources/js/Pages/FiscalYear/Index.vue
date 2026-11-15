<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import DataTable from '@/Components/table/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { Clock, Returns, ShieldCheck } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordActions from '@modules/Items/resources/js/Pages/parts/RecordActions.vue';
import { useRecordModal } from '@modules/Items/resources/js/Pages/parts/useRecordModal';

import type { StatusBadge } from '../parts/statusBadge';

defineOptions({ layout: AdminLayout });

interface FiscalYearForm {
    name: string;
    starts_at: string;
    ends_at: string;
    is_default: boolean;
}

interface Row {
    id: number;
    name: string;
    starts_at: string | null;
    ends_at: string | null;
    status: StatusBadge | null;
    is_open: boolean;
    is_default: boolean;
    periods_count: number;
    form: FiscalYearForm;
}

const props = defineProps<{ table: TablePayload<Row>; statuses: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'starts_at', label: t('Start Date'), sort: 'starts_at' },
    { key: 'ends_at', label: t('End Date'), sort: 'ends_at' },
    { key: 'status', label: t('Status') },
    { key: 'is_default', label: t('Default') },
    { key: 'periods_count', label: t('Periods') },
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'status', label: t('Status'), options: props.statuses },
    { type: 'boolean', key: 'is_default', label: t('Default') },
    { type: 'date-range', key: 'starts_at', label: t('Start Date') },
]);

const { open, editingId, form, add, edit, submit } = useRecordModal<FiscalYearForm>('accounting.fiscal-years', () => ({
    name: '',
    starts_at: '',
    ends_at: '',
    is_default: false,
}));

async function close(row: Row): Promise<void> {
    if (await confirm({ title: `${t('Close')} - ${row.name}`, description: t('A closing entry will transfer the net profit or loss of the year to retained earnings, then all periods will be closed.'), confirmLabel: t('Close') })) {
        router.post(route('accounting.fiscal-years.close', row.id), {}, { preserveScroll: true });
    }
}

async function reopen(row: Row): Promise<void> {
    if (await confirm({ title: `${t('Reopen')} - ${row.name}`, tone: 'primary', confirmLabel: t('Reopen') })) {
        router.post(route('accounting.fiscal-years.reopen', row.id), {}, { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Fiscal Years')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Fiscal Year Create')" variant="primary" @click="add">{{ t('Add Fiscal Year') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No fiscal years found')">
        <template #cell-name="{ row }">
            <button v-if="can('Fiscal Year Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="edit(row.id, row.form)">{{ row.name }}</button>
            <template v-else>{{ row.name }}</template>
        </template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone">{{ row.status.label }}</Badge>
        </template>
        <template #cell-is_default="{ row }">
            <Badge :tone="row.is_default ? 'success' : 'neutral'">{{ row.is_default ? t('Yes') : t('No') }}</Badge>
        </template>
        <template #cell-periods_count="{ row }">
            <Link v-if="row.periods_count" :href="route('accounting.fiscal-years.periods', row.id)" class="text-brand-600 tabular-nums hover:underline dark:text-brand-400">{{ row.periods_count }}</Link>
            <span v-else class="tabular-nums">0</span>
        </template>
        <template #empty>
            <Button v-if="can('Fiscal Year Create')" variant="primary" @click="add">{{ t('Add Fiscal Year') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Fiscal Year"
                routes="accounting.fiscal-years"
                :id="row.id"
                :title="row.name"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Start Date'), value: row.starts_at },
                    { label: t('End Date'), value: row.ends_at },
                    { label: t('Status'), value: row.status?.label },
                    { label: t('Default'), value: row.is_default ? t('Yes') : t('No') },
                    { label: t('Periods'), value: row.periods_count },
                ]"
                @edit="edit(row.id, row.form)"
            >
                <DropdownItem :href="route('accounting.fiscal-years.periods', row.id)"><Clock />{{ t('Accounting Periods') }}</DropdownItem>
                <DropdownItem v-if="row.is_open && can('Fiscal Year Close')" @select="close(row)"><ShieldCheck />{{ t('Close') }}</DropdownItem>
                <DropdownItem v-if="!row.is_open && can('Fiscal Year Reopen')" @select="reopen(row)"><Returns />{{ t('Reopen') }}</DropdownItem>
            </RecordActions>
        </template>
    </DataTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Fiscal Year')" width="max-w-xl">
        <form id="fiscal-year-form" class="grid gap-4 md:grid-cols-2" @submit.prevent="submit">
            <TextInput v-model="form.name" class="md:col-span-2" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
            <TextInput v-model="form.starts_at" type="date" :label="t('Start Date')" required :error="form.errors.starts_at" />
            <TextInput v-model="form.ends_at" type="date" :label="t('End Date')" required :error="form.errors.ends_at" />
            <Toggle v-model="form.is_default" class="md:col-span-2" :label="t('Default')" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>

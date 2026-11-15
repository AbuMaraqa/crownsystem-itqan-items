<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import type { Option } from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import TablePagination from '@/Components/table/TablePagination.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { ArrowDown } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableMeta } from '@/types/table';

import { tableClasses as c } from './parts/types';
import { useReportFilters } from './parts/useReportFilters';

defineOptions({ layout: AdminLayout });

interface Line {
    id: number;
    date: string | null;
    entry_id: number;
    entry_number: string | null;
    reference_no: string | null;
    account: string;
    description: string | null;
    debit: string;
    credit: string;
    balance: string;
}

const props = defineProps<{
    filters: { account_id: number | null; from: string | null; to: string | null; search: string };
    account: Option | null;
    showBalance: boolean;
    showOpening: boolean;
    ledger: { opening: string; debit: string; credit: string; closing: string };
    lines: Line[];
    meta: TableMeta;
}>();

const { filters, loading, query, reload } = useReportFilters(
    { ...props.filters, account_id: props.filters.account_id as string | number | null, per_page: props.meta.per_page as number | null },
    { debounce: ['search'] },
);

const exportUrl = computed(() => route('accounting.reports.ledger.export', query({ per_page: undefined })));
const columns = computed(() => (props.showBalance ? 8 : 7));
</script>

<template>
    <PageHeader :title="t('Ledger')">
        <template #actions>
            <Button :href="exportUrl" external><ArrowDown />{{ t('Export to Excel') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <Card>
            <div class="grid gap-4 md:grid-cols-6">
                <div class="md:col-span-2">
                    <TextInput v-model="filters.search" type="search" :label="t('Search')" :placeholder="t('Search ledger')" />
                </div>
                <div class="md:col-span-2">
                    <RemoteSelect
                        v-model="filters.account_id"
                        :url="route('accounting.reports.ledger.accounts')"
                        :initial="account ? [account] : []"
                        :label="t('Account')"
                        :placeholder="t('All Accounts')"
                    />
                </div>
                <TextInput v-model="filters.from" type="date" :label="t('From')" />
                <TextInput v-model="filters.to" type="date" :label="t('To')" />
            </div>
        </Card>

        <Card>
            <div class="-m-5">
                <div class="overflow-x-auto transition-opacity" :class="{ 'opacity-60': loading }">
                    <table :class="[c.table, 'min-w-[60rem]']">
                        <thead :class="c.head">
                            <tr class="border-b border-line">
                                <th :class="[c.th, 'text-start']">{{ t('Date') }}</th>
                                <th :class="[c.th, 'text-start']">{{ t('Entry Number') }}</th>
                                <th :class="[c.th, 'text-start']">{{ t('Reference') }}</th>
                                <th :class="[c.th, 'text-start']">{{ t('Account') }}</th>
                                <th :class="[c.th, 'text-start']">{{ t('Description') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('Debit') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('Credit') }}</th>
                                <th v-if="showBalance" :class="[c.th, 'text-end']">{{ t('Balance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="showOpening" :class="[c.row, 'bg-gray-50 font-semibold dark:bg-white/5']">
                                <td colspan="7" :class="c.td">{{ t('Opening Balance') }}</td>
                                <td :class="c.number" dir="ltr">{{ ledger.opening }}</td>
                            </tr>
                            <tr v-for="line in lines" :key="line.id" :class="c.row">
                                <td :class="[c.td, 'whitespace-nowrap']">{{ line.date }}</td>
                                <td :class="c.td">
                                    <Link v-if="can('Journal Entry View')" :href="route('accounting.journal-entries.show', line.entry_id)" :class="c.link" dir="ltr">{{ line.entry_number }}</Link>
                                    <bdi v-else dir="ltr">{{ line.entry_number }}</bdi>
                                </td>
                                <td :class="c.td"><bdi>{{ line.reference_no }}</bdi></td>
                                <td :class="c.td">{{ line.account }}</td>
                                <td :class="c.td">{{ line.description }}</td>
                                <td :class="c.number" dir="ltr">{{ line.debit }}</td>
                                <td :class="c.number" dir="ltr">{{ line.credit }}</td>
                                <td v-if="showBalance" :class="c.number" dir="ltr">{{ line.balance }}</td>
                            </tr>
                            <tr v-if="!lines.length">
                                <td :colspan="columns" :class="c.empty">{{ t('No records found') }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr :class="c.foot">
                                <td colspan="5" :class="c.td">{{ t('Total') }}</td>
                                <td :class="c.number" dir="ltr">{{ ledger.debit }}</td>
                                <td :class="c.number" dir="ltr">{{ ledger.credit }}</td>
                                <td v-if="showBalance" :class="c.number" dir="ltr">{{ ledger.closing }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <TablePagination v-if="meta.total > 0" :meta="meta" @page="(page) => reload({ page })" @per-page="(size) => (filters.per_page = size)" />
            </div>
        </Card>
    </div>
</template>

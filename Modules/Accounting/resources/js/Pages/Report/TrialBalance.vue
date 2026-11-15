<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { ArrowDown } from '@/lib/icons';
import { route } from '@/lib/route';

import { tableClasses as c } from './parts/types';
import { useReportFilters } from './parts/useReportFilters';

defineOptions({ layout: AdminLayout });

interface Row {
    account_id: number;
    code: string;
    name: string;
    indent: number;
    is_postable: boolean;
    opening_debit: string;
    opening_credit: string;
    debit: string;
    credit: string;
    closing_debit: string;
    closing_credit: string;
}

type Totals = Record<'opening_debit' | 'opening_credit' | 'debit' | 'credit' | 'closing_debit' | 'closing_credit', string>;

const props = defineProps<{
    filters: { from: string | null; to: string | null; withGroups: boolean; includeZero: boolean };
    rows: Row[];
    totals: Totals;
    balanced: boolean;
}>();

// An empty date is sent as empty: no lower or upper limit, instead of the default dates.
const { filters, loading, query } = useReportFilters({ ...props.filters }, { keepEmpty: ['from', 'to'] });

const exportUrl = computed(() => route('accounting.reports.trial-balance.export', query()));

const ledgerUrl = (row: Row): string =>
    route('accounting.reports.ledger', { account_id: row.account_id, ...(filters.from ? { from: filters.from } : {}), ...(filters.to ? { to: filters.to } : {}) });

const amounts = ['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'] as const;
</script>

<template>
    <PageHeader :title="t('Trial Balance')">
        <template #actions>
            <Button :href="exportUrl" external><ArrowDown />{{ t('Export to Excel') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <Card>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                <TextInput v-model="filters.from" type="date" :label="t('From')" />
                <TextInput v-model="filters.to" type="date" :label="t('To')" />
                <Toggle v-model="filters.withGroups" :label="t('Show group accounts')" />
                <Toggle v-model="filters.includeZero" :label="t('Show zero balances')" />
            </div>
        </Card>

        <Card>
            <div class="-m-5 overflow-x-auto transition-opacity" :class="{ 'opacity-60': loading }">
                <table :class="[c.table, 'min-w-[60rem]']">
                    <thead :class="c.head">
                        <tr class="border-b border-line">
                            <th rowspan="2" :class="[c.th, 'text-start']">{{ t('Code') }}</th>
                            <th rowspan="2" :class="[c.th, 'text-start']">{{ t('Account') }}</th>
                            <th colspan="2" :class="[c.th, 'text-center']">{{ t('Opening Balance') }}</th>
                            <th colspan="2" :class="[c.th, 'text-center']">{{ t('Period Movement') }}</th>
                            <th colspan="2" :class="[c.th, 'text-center']">{{ t('Closing Balance') }}</th>
                        </tr>
                        <tr class="border-b border-line">
                            <template v-for="n in 3" :key="n">
                                <th :class="[c.th, 'text-end']">{{ t('Debit') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('Credit') }}</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.account_id" :class="[c.row, !row.is_postable && 'bg-gray-50 font-semibold dark:bg-white/5']">
                            <td :class="c.td"><bdi dir="ltr">{{ row.code }}</bdi></td>
                            <td :class="c.td">
                                <Link :href="ledgerUrl(row)" :class="c.link" :style="{ paddingInlineStart: `${row.indent * 1.25}rem` }">{{ row.name }}</Link>
                            </td>
                            <td v-for="key in amounts" :key="key" :class="c.number" dir="ltr">{{ row[key] }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="8" :class="c.empty">{{ t('No records found') }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr :class="c.foot">
                            <td colspan="2" :class="c.td">{{ t('Total') }}</td>
                            <td v-for="key in amounts" :key="key" :class="c.number" dir="ltr">{{ totals[key] }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>

        <p v-if="!balanced" class="text-sm font-semibold text-danger" role="alert">{{ t('The trial balance is not balanced. Please review the journal entries.') }}</p>
    </div>
</template>

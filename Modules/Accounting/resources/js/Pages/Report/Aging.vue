<script setup lang="ts">
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { ArrowDown } from '@/lib/icons';
import { route } from '@/lib/route';
import type { UserCellData } from '@/types/user';

import { tableClasses as c } from './parts/types';
import { useReportFilters } from './parts/useReportFilters';

defineOptions({ layout: AdminLayout });

interface Row {
    client: UserCellData | null;
    invoices: number;
    amounts: Record<string, string>;
    overdue: boolean;
    total: string;
}

const props = defineProps<{
    kind: 'receivable' | 'payable';
    title: string;
    partyLabel: string;
    /** `accounting.reports.receivable-aging` or `payable-aging`; the export is `<routeName>.export`. */
    routeName: string;
    filters: { asOf: string | null };
    buckets: { key: string; label: string }[];
    rows: Row[];
    totals: Record<string, string>;
}>();

const { filters, loading, query } = useReportFilters({ ...props.filters });

const exportUrl = computed(() => route(`${props.routeName}.export`, query()));

// The party opens its customer history, where its statement and documents are.
const historyUrl = (client: UserCellData): string | null =>
    can('Customer History View') ? route('accounting.reports.customer-history', { client: client.id }) : null;
</script>

<template>
    <PageHeader :title="title">
        <template #actions>
            <Button :href="exportUrl" external><ArrowDown />{{ t('Export to Excel') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <Card>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                <TextInput v-model="filters.asOf" type="date" :label="t('As of')" />
                <p class="text-sm text-muted sm:col-span-1 lg:col-span-3">{{ t('Amounts are in the base currency, by due date (or invoice date when no due date).') }}</p>
            </div>
        </Card>

        <Card>
            <div class="-m-5 overflow-x-auto transition-opacity" :class="{ 'opacity-60': loading }">
                <table :class="[c.table, 'min-w-[56rem]']">
                    <thead :class="c.head">
                        <tr class="border-b border-line">
                            <th :class="[c.th, 'text-start']">{{ partyLabel }}</th>
                            <th :class="[c.th, 'text-end']">{{ t('Invoices') }}</th>
                            <th v-for="bucket in buckets" :key="bucket.key" :class="[c.th, 'text-end']">{{ bucket.label }}</th>
                            <th :class="[c.th, 'text-end']">{{ t('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in rows" :key="row.client?.id ?? `row-${index}`" :class="c.row">
                            <td :class="c.td">
                                <UserCell v-if="row.client" :name="row.client.name" :avatar="row.client.avatar" :detail="row.client.detail" :href="historyUrl(row.client)" />
                                <span v-else>-</span>
                            </td>
                            <td :class="c.number">{{ row.invoices }}</td>
                            <td
                                v-for="bucket in buckets"
                                :key="bucket.key"
                                :class="[c.number, bucket.key === 'older' && row.overdue && 'font-semibold text-danger']"
                                dir="ltr"
                            >
                                {{ row.amounts[bucket.key] }}
                            </td>
                            <td :class="[c.number, 'font-semibold']" dir="ltr">{{ row.total }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td :colspan="buckets.length + 3" :class="c.empty">{{ t('No records found') }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr :class="c.foot">
                            <td colspan="2" :class="c.td">{{ t('Total') }}</td>
                            <td v-for="bucket in buckets" :key="bucket.key" :class="c.number" dir="ltr">{{ totals[bucket.key] }}</td>
                            <td :class="c.number" dir="ltr">{{ totals.total }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>
    </div>
</template>

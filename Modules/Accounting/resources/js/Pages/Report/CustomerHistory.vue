<script setup lang="ts">
import { computed, watch } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput, { type Option } from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { ArrowUpDown, Printer, X } from '@/lib/icons';
import { route } from '@/lib/route';

import type { StatusBadge } from '../parts/statusBadge';
import { tableClasses as c } from './parts/types';
import { useReportFilters } from './parts/useReportFilters';

defineOptions({ layout: AdminLayout });

type Tone = StatusBadge['tone'];

type Filters = {
    client: number | string | null;
    tab: string;
    mode: string | number | null;
    from: string | null;
    to: string | null;
    group: string | number | null;
    document_search: string;
    item_search: string;
    movement_search: string;
    product: number | null;
};

interface Statement {
    has_account: boolean;
    opening: string;
    closing: string;
    debit: string;
    credit: string;
    rows: {
        id: number;
        date: string | null;
        entry_number: string | null;
        source: string;
        reference_no: string | null;
        description: string | null;
        voided: boolean;
        debit: string;
        credit: string;
        balance: string;
        url: string | null;
        pdf_url: string | null;
        details: { description: string | null; quantity: string | null; total: string }[];
    }[];
}

interface DocumentRow {
    key: string;
    date: string | null;
    type: string;
    direction: string | null;
    reference_no: string | null;
    status: StatusBadge | null;
    amount: string | null;
    base_amount: string | null;
    url: string | null;
    pdf_url: string | null;
}

interface ItemRow {
    product_id: number;
    name: string;
    codes: string;
    last_sale_price: string | null;
    last_sale_discount: string | null;
    last_sale_date: string | null;
    last_sale_reference: string | null;
    price_range: string | null;
    sales_count: number | null;
    sold_quantity: string | null;
    last_purchase_price: string | null;
    last_purchase_date: string | null;
}

interface MovementRow {
    key: string;
    date: string | null;
    type: string;
    direction: 'in' | 'out';
    posted: boolean;
    reference_no: string | null;
    name: string;
    quantity: string;
    unit_price: string;
    discount: string | null;
    total: string;
}

const props = defineProps<{
    filters: Filters;
    client: { id: number; name: string; phone: string | null; email: string | null; tax_number: string | null; address: string | null; roles: string[] } | null;
    clientOption: Option | null;
    documentGroups: Option[];
    summary?: { label: string; value: string; hint: string; tone: Tone }[];
    statement?: Statement;
    documents?: DocumentRow[];
    items?: ItemRow[];
    movements?: MovementRow[];
    movementProduct?: string | null;
}>();

const { filters, loading } = useReportFilters<Filters>({ ...props.filters }, { debounce: ['document_search', 'item_search', 'movement_search'] });

// Another client starts again from all of its items.
watch(
    () => filters.client,
    () => (filters.product = null),
);

const tabs = computed(() => [
    { value: 'statement', label: t('Account Statement') },
    { value: 'documents', label: t('Documents') },
    { value: 'items', label: t('Last Item Prices') },
    { value: 'movements', label: t('Item Movements') },
]);

const statementModes = computed<Option[]>(() => [
    { value: 'summary', label: t('Standard') },
    { value: 'detailed', label: t('Detailed') },
]);

const toneClasses: Record<Tone, string> = {
    brand: 'text-brand-600 dark:text-brand-400',
    success: 'text-success-600 dark:text-success-400',
    warning: 'text-warning-600 dark:text-warning-400',
    danger: 'text-danger',
    info: 'text-blue-light-600 dark:text-blue-light-400',
    neutral: 'text-gray-900 dark:text-white',
};

/** From the last prices table straight to that item's movements. */
function showMovements(productId: number | null): void {
    filters.product = productId;
    filters.tab = 'movements';
}

const details = computed(() =>
    props.client
        ? [
              { label: t('Phone'), value: props.client.phone },
              { label: t('Email'), value: props.client.email },
              { label: t('Tax Number'), value: props.client.tax_number },
              { label: t('Address'), value: props.client.address },
          ].filter((field) => field.value)
        : [],
);
</script>

<template>
    <PageHeader :title="t('Customer History')" />

    <div class="grid gap-4">
        <Card>
            <div class="grid gap-4 md:grid-cols-6">
                <div class="md:col-span-4">
                    <RemoteSelect
                        v-model="filters.client"
                        :url="route('accounting.reports.customer-history.clients')"
                        :initial="clientOption ? [clientOption] : []"
                        :label="t('Client')"
                        :placeholder="t('Search by name, email or phone')"
                    />
                </div>
                <TextInput v-model="filters.from" type="date" :label="t('From')" />
                <TextInput v-model="filters.to" type="date" :label="t('To')" />
            </div>
        </Card>

        <Card v-if="!client">
            <p class="text-center text-sm text-muted">{{ t('Select a client to view their history') }}</p>
        </Card>

        <template v-else>
            <Card>
                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div>
                        <div class="text-lg font-semibold text-gray-900 dark:text-white">{{ client.name }}</div>
                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted">
                            <span v-for="field in details" :key="field.label">{{ field.label }}: <bdi>{{ field.value }}</bdi></span>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Badge v-for="role in client.roles" :key="role">{{ role }}</Badge>
                    </div>
                </div>
            </Card>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <div v-for="card in summary" :key="card.label" class="rounded-xl border border-line bg-card p-4 shadow-card">
                    <div class="text-sm text-muted">{{ card.label }}</div>
                    <div class="mt-1 text-xl font-bold tabular-nums" :class="toneClasses[card.tone]">{{ card.value }}</div>
                    <div class="mt-1 truncate text-xs text-muted" :title="card.hint">{{ card.hint }}</div>
                </div>
            </div>
            <p class="text-xs text-muted">{{ t('Totals are in the base currency and include posted documents only.') }}</p>

            <Card>
                <Tabs v-model="filters.tab" :tabs="tabs">
                    <template #statement>
                        <div v-if="statement" class="transition-opacity" :class="{ 'opacity-60': loading }">
                            <p v-if="!statement.has_account" class="text-center text-sm text-muted">
                                {{ t('This client has no sub-account yet; it is created with the first posted document.') }}
                            </p>
                            <template v-else>
                                <div class="mb-4 grid gap-4 md:grid-cols-4">
                                    <SelectInput v-model="filters.mode" :options="statementModes" :label="t('Statement Type')" />
                                </div>
                                <div class="-mx-5 overflow-x-auto">
                                    <table :class="[c.table, 'min-w-[64rem]']">
                                        <thead :class="c.head">
                                            <tr class="border-b border-line">
                                                <th :class="[c.th, 'text-start']">{{ t('Date') }}</th>
                                                <th :class="[c.th, 'text-start']">{{ t('Entry Number') }}</th>
                                                <th :class="[c.th, 'text-start']">{{ t('Source') }}</th>
                                                <th :class="[c.th, 'text-start']">{{ t('Reference No') }}</th>
                                                <th :class="[c.th, 'text-start']">{{ t('Description') }}</th>
                                                <th :class="[c.th, 'text-end']">{{ t('Debit') }}</th>
                                                <th :class="[c.th, 'text-end']">{{ t('Credit') }}</th>
                                                <th :class="[c.th, 'text-end']">{{ t('Balance') }}</th>
                                                <th :class="c.th"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr :class="[c.row, 'font-semibold']">
                                                <td colspan="7" :class="c.td">{{ t('Opening Balance') }}</td>
                                                <td :class="c.number" dir="ltr">{{ statement.opening }}</td>
                                                <td></td>
                                            </tr>
                                            <template v-for="row in statement.rows" :key="row.id">
                                                <tr :class="[c.row, row.voided && 'line-through opacity-60']">
                                                    <td :class="[c.td, 'whitespace-nowrap']">{{ row.date }}</td>
                                                    <td :class="c.td"><bdi dir="ltr">{{ row.entry_number }}</bdi></td>
                                                    <td :class="c.td">{{ row.source }}</td>
                                                    <td :class="c.td">
                                                        <a v-if="row.url" :href="row.url" target="_blank" rel="noopener" :class="c.link">{{ row.reference_no || '-' }}</a>
                                                        <bdi v-else>{{ row.reference_no || '-' }}</bdi>
                                                    </td>
                                                    <td :class="c.td">{{ row.description }}</td>
                                                    <td :class="c.number" dir="ltr">{{ row.debit }}</td>
                                                    <td :class="c.number" dir="ltr">{{ row.credit }}</td>
                                                    <td :class="c.number" dir="ltr">{{ row.balance }}</td>
                                                    <td :class="[c.td, 'text-end']">
                                                        <a v-if="row.pdf_url" :href="row.pdf_url" target="_blank" rel="noopener" :class="c.link" :title="t('Print')" :aria-label="t('Print')"><Printer class="size-4" /></a>
                                                    </td>
                                                </tr>
                                                <tr v-if="row.details.length">
                                                    <td></td>
                                                    <td colspan="8" class="px-4 pb-2.5">
                                                        <table class="w-full rounded-md bg-gray-50 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                                            <tr v-for="(detail, index) in row.details" :key="index">
                                                                <td class="px-2 py-1">{{ detail.description }}</td>
                                                                <td class="px-2 py-1 text-end whitespace-nowrap tabular-nums" dir="ltr">{{ detail.quantity }}</td>
                                                                <td class="px-2 py-1 text-end font-medium whitespace-nowrap tabular-nums" dir="ltr">{{ detail.total }}</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            </template>
                                            <tr v-if="!statement.rows.length">
                                                <td colspan="9" :class="c.empty">{{ t('No records found') }}</td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr :class="c.foot">
                                                <td colspan="5" :class="c.td">{{ t('Closing Balance') }}</td>
                                                <td :class="c.number" dir="ltr">{{ statement.debit }}</td>
                                                <td :class="c.number" dir="ltr">{{ statement.credit }}</td>
                                                <td :class="c.number" dir="ltr">{{ statement.closing }}</td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template #documents>
                        <div v-if="documents" class="transition-opacity" :class="{ 'opacity-60': loading }">
                            <div class="mb-4 grid gap-4 md:grid-cols-4">
                                <div class="md:col-span-2">
                                    <TextInput v-model="filters.document_search" type="search" :placeholder="t('Search by reference or notes')" />
                                </div>
                                <SelectInput v-model="filters.group" :options="documentGroups" :placeholder="t('All Documents')" />
                            </div>
                            <div class="-mx-5 overflow-x-auto">
                                <table :class="[c.table, 'min-w-[52rem]']">
                                    <thead :class="c.head">
                                        <tr class="border-b border-line">
                                            <th :class="[c.th, 'text-start']">{{ t('Date') }}</th>
                                            <th :class="[c.th, 'text-start']">{{ t('Type') }}</th>
                                            <th :class="[c.th, 'text-start']">{{ t('Reference No') }}</th>
                                            <th :class="[c.th, 'text-start']">{{ t('Status') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Amount') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Base Amount') }}</th>
                                            <th :class="c.th"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="document in documents" :key="document.key" :class="c.row">
                                            <td :class="[c.td, 'whitespace-nowrap']">{{ document.date }}</td>
                                            <td :class="c.td">
                                                {{ document.type }}
                                                <span v-if="document.direction" class="text-xs text-muted">({{ document.direction }})</span>
                                            </td>
                                            <td :class="c.td">
                                                <a v-if="document.url" :href="document.url" target="_blank" rel="noopener" :class="c.link">{{ document.reference_no || '-' }}</a>
                                                <bdi v-else>{{ document.reference_no || '-' }}</bdi>
                                            </td>
                                            <td :class="c.td">
                                                <Badge v-if="document.status" :tone="document.status.tone">{{ document.status.label }}</Badge>
                                            </td>
                                            <td :class="c.number" dir="ltr">{{ document.amount ?? '-' }}</td>
                                            <td :class="c.number" dir="ltr">{{ document.base_amount ?? '-' }}</td>
                                            <td :class="[c.td, 'text-end']">
                                                <a v-if="document.pdf_url" :href="document.pdf_url" target="_blank" rel="noopener" :class="c.link" :title="t('Print')" :aria-label="t('Print')"><Printer class="size-4" /></a>
                                            </td>
                                        </tr>
                                        <tr v-if="!documents.length">
                                            <td colspan="7" :class="c.empty">{{ t('No records found') }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    <template #items>
                        <div v-if="items" class="transition-opacity" :class="{ 'opacity-60': loading }">
                            <div class="mb-4 grid gap-4 md:grid-cols-4">
                                <div class="md:col-span-2">
                                    <TextInput v-model="filters.item_search" type="search" :placeholder="t('Search by item name, SKU or barcode')" />
                                </div>
                            </div>
                            <div class="-mx-5 overflow-x-auto">
                                <table :class="[c.table, 'min-w-[64rem]']">
                                    <thead :class="c.head">
                                        <tr class="border-b border-line">
                                            <th :class="[c.th, 'text-start']">{{ t('Item') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Last Sale Price') }}</th>
                                            <th :class="[c.th, 'text-start']">{{ t('Last Sale Date') }}</th>
                                            <th :class="[c.th, 'text-start']">{{ t('Invoice') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Price Range') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Times Sold') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Sold Quantity') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Last Purchase Price') }}</th>
                                            <th :class="c.th"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="item in items" :key="item.product_id" :class="c.row">
                                            <td :class="c.td">
                                                <div class="font-medium">{{ item.name }}</div>
                                                <div v-if="item.codes" class="text-xs text-muted" dir="ltr">{{ item.codes }}</div>
                                            </td>
                                            <td :class="[c.number, 'font-semibold text-brand-600 dark:text-brand-400']">
                                                <template v-if="item.last_sale_price">
                                                    <span dir="ltr">{{ item.last_sale_price }}</span>
                                                    <div v-if="item.last_sale_discount" class="text-xs font-normal text-muted">{{ t('Discount') }}: <span dir="ltr">{{ item.last_sale_discount }}</span></div>
                                                </template>
                                                <template v-else>-</template>
                                            </td>
                                            <td :class="[c.td, 'whitespace-nowrap']">{{ item.last_sale_date ?? '-' }}</td>
                                            <td :class="c.td"><bdi>{{ item.last_sale_reference ?? '-' }}</bdi></td>
                                            <td :class="c.number" dir="ltr">{{ item.price_range ?? '-' }}</td>
                                            <td :class="c.number">{{ item.sales_count ?? '-' }}</td>
                                            <td :class="c.number" dir="ltr">{{ item.sold_quantity ?? '-' }}</td>
                                            <td :class="c.number">
                                                <template v-if="item.last_purchase_price">
                                                    <span dir="ltr">{{ item.last_purchase_price }}</span>
                                                    <div class="text-xs text-muted">{{ item.last_purchase_date }}</div>
                                                </template>
                                                <template v-else>-</template>
                                            </td>
                                            <td :class="[c.td, 'text-end']">
                                                <button type="button" :class="c.link" :title="t('Item Movements')" :aria-label="t('Item Movements')" @click="showMovements(item.product_id)">
                                                    <ArrowUpDown class="size-4" />
                                                </button>
                                            </td>
                                        </tr>
                                        <tr v-if="!items.length">
                                            <td colspan="9" :class="c.empty">{{ t('No records found') }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    <template #movements>
                        <div v-if="movements" class="transition-opacity" :class="{ 'opacity-60': loading }">
                            <div class="mb-4 grid gap-4 md:grid-cols-4">
                                <div class="md:col-span-2">
                                    <TextInput v-model="filters.movement_search" type="search" :placeholder="t('Search by item, SKU, barcode or reference')" />
                                </div>
                                <div v-if="filters.product" class="flex items-center gap-2 text-sm md:col-span-2">
                                    <Badge tone="brand">{{ movementProduct ?? `#${filters.product}` }}</Badge>
                                    <button type="button" class="inline-flex items-center gap-1 text-muted hover:text-ink" @click="showMovements(null)">
                                        <X class="size-3.5" />{{ t('Show all items') }}
                                    </button>
                                </div>
                            </div>
                            <div class="-mx-5 overflow-x-auto">
                                <table :class="[c.table, 'min-w-[56rem]']">
                                    <thead :class="c.head">
                                        <tr class="border-b border-line">
                                            <th :class="[c.th, 'text-start']">{{ t('Date') }}</th>
                                            <th :class="[c.th, 'text-start']">{{ t('Type') }}</th>
                                            <th :class="[c.th, 'text-start']">{{ t('Reference No') }}</th>
                                            <th :class="[c.th, 'text-start']">{{ t('Item') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Quantity') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Unit Price') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Discount') }}</th>
                                            <th :class="[c.th, 'text-end']">{{ t('Total') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="movement in movements" :key="movement.key" :class="c.row">
                                            <td :class="[c.td, 'whitespace-nowrap']">{{ movement.date }}</td>
                                            <td :class="[c.td, 'whitespace-nowrap']">
                                                <span :class="movement.direction === 'out' ? 'text-danger' : 'text-success-600 dark:text-success-400'">{{ movement.type }}</span>
                                                <Badge v-if="!movement.posted" class="ms-1">{{ t('Draft') }}</Badge>
                                            </td>
                                            <td :class="c.td"><bdi>{{ movement.reference_no || '-' }}</bdi></td>
                                            <td :class="c.td">{{ movement.name }}</td>
                                            <td :class="c.number" dir="ltr">{{ movement.quantity }}</td>
                                            <td :class="c.number" dir="ltr">{{ movement.unit_price }}</td>
                                            <td :class="c.number" dir="ltr">{{ movement.discount ?? '-' }}</td>
                                            <td :class="c.number" dir="ltr">{{ movement.total }}</td>
                                        </tr>
                                        <tr v-if="!movements.length">
                                            <td colspan="8" :class="c.empty">{{ t('No records found') }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
                </Tabs>
            </Card>
        </template>
    </div>
</template>

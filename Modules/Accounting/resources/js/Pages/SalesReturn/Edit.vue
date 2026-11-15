<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import FormActions from '@/Components/form/FormActions.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { CircleCheck, Printer, Trash2, X } from '@/lib/icons';
import { route } from '@/lib/route';

import DocumentTotals from '../parts/items-grid/DocumentTotals.vue';
import ItemsGrid from '../parts/items-grid/ItemsGrid.vue';
import type { DocumentTotals as Totals } from '../parts/items-grid/types';
import PaymentAllocationPanel from '../parts/PaymentAllocationPanel.vue';
import type { Option } from '../parts/types';
import { DOCUMENT, type AllocationPanelData, type SalesReturnForm, type SalesReturnRecord } from './types';

/**
 * A sales return on the shared items grid: the invoice's returnable lines with their prices; only
 * the returned quantity of each item and the returned pieces of each sub-item are typed, capped by
 * what was invoiced and not yet returned (ReturnPieces). Posting reverses the revenue, brings the
 * goods back at their issue cost and settles the invoice; the rest stays as the customer's credit.
 */
defineOptions({ layout: AdminLayout });

const props = defineProps<{ salesReturn: SalesReturnForm; record: SalesReturnRecord; allocationPanel: AllocationPanelData | null; warehouseOptions: Option[] }>();

const form = useForm<SalesReturnForm>(props.salesReturn);
const totals = ref<Totals>({ sub_total: 0, items_discount: 0, document_discount: 0, tax_amount: 0, total_amount: 0 });
const reason = ref('');

watch(
    () => props.salesReturn,
    (value) => {
        form.defaults(value);
        form.reset();
    },
);

const id = computed(() => props.record.id);
const editable = computed(() => props.record.editable);

const gridConfig = computed(() => ({
    document: DOCUMENT,
    editable: false,
    pricesEditable: false,
    actualWeightEditable: false,
    quantitiesEditable: editable.value,
    quantityLimitLabel: t('Returnable'),
    showDiscount: true,
    barcode: false,
    groups: false,
}));

const gridContext = computed(() => ({
    partyId: null,
    exchangeRate: props.record.exchange_rate,
    warehouseId: form.warehouse_id,
    documentDiscount: props.record.invoice_discount,
    taxRate: props.record.tax_rate,
}));

const itemsError = computed(() => Object.entries(form.errors as Record<string, string>).find(([key]) => key === 'items' || key.startsWith('items.'))?.[1]);

async function post(): Promise<void> {
    if (!(await confirm({ title: t('Post this return? The returned goods move in stock and its journal entry is posted.'), confirmLabel: t('Post Return'), tone: 'primary' }))) return;

    const url = route('accounting.sales-returns.post', id.value);

    if (editable.value) form.post(url, { preserveScroll: true });
    else router.post(url, {}, { preserveScroll: true });
}

async function cancel(): Promise<void> {
    if (await confirm({ title: t('Cancel this return? Its stock movement and journal entries are reversed.'), confirmLabel: t('Cancel') })) {
        router.post(route('accounting.sales-returns.cancel', id.value), { reason: reason.value || null }, { preserveScroll: true, onSuccess: () => (reason.value = '') });
    }
}

async function remove(): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: props.record.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.sales-returns.destroy', id.value));
    }
}
</script>

<template>
    <PageHeader :title="`${t('Sales Return')} ${record.reference_no}`" :parents="[{ title: t('Sales Returns'), href: route('accounting.sales-returns.index') }]">
        <template #actions>
            <Badge v-if="record.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge>
            <Button v-if="record.can_post && can('Sales Return Post')" variant="primary" :loading="form.processing" @click="post"><CircleCheck />{{ t('Post Return') }}</Button>
            <template v-if="record.can_cancel && can('Sales Return Cancel')">
                <TextInput v-model="reason" class="w-56" :placeholder="t('Cancellation reason (optional)')" />
                <Button variant="danger" @click="cancel"><X />{{ t('Cancel') }}</Button>
            </template>
            <Button v-if="can('Sales Return View')" external new-tab :href="route('accounting.sales-returns.pdf', id)"><Printer />{{ t('Print') }}</Button>
            <Button v-if="record.can_delete && can('Sales Return Delete')" variant="ghost" :aria-label="t('Delete')" @click="remove"><Trash2 /></Button>
        </template>
    </PageHeader>

    <div class="mb-4 space-y-3 empty:hidden">
        <div v-if="record.cancelled" class="rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-sm font-medium text-error-700 dark:border-error-500/25 dark:bg-error-500/10 dark:text-error-400">
            {{ t('This document was cancelled on :date.', { date: record.cancelled_at ?? '' }) }}
            <template v-if="record.cancellation_reason"> — {{ record.cancellation_reason }}</template>
        </div>
        <p v-if="record.sales_invoice" class="text-sm text-muted">
            {{ t('Returned from invoice') }}
            <a v-if="can('Sales Invoice View List')" class="font-semibold text-brand-600 hover:underline dark:text-brand-400" :href="route('accounting.sales-invoices.edit', record.sales_invoice.id)" dir="ltr">{{ record.sales_invoice.reference_no }}</a>
            <span v-else class="font-semibold" dir="ltr">{{ record.sales_invoice.reference_no }}</span>
        </p>
    </div>

    <form class="grid gap-4" @submit.prevent="form.put(route('accounting.sales-returns.update', id), { preserveScroll: true })">
        <Card :title="t(':document Details', { document: t('Sales Return') })">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TextInput :model-value="record.reference_no" :label="t('Reference No')" dir="ltr" disabled />
                <TextInput :model-value="record.status?.label ?? ''" :label="t('Status')" disabled />
                <TextInput :model-value="record.sales_invoice?.reference_no ?? '-'" :label="t('Sales Invoice')" dir="ltr" disabled />
                <TextInput :model-value="record.client ?? '-'" :label="t('Party')" disabled />
                <TextInput v-model="form.return_date" type="date" :label="t('Return Date')" required :disabled="!editable" :error="form.errors.return_date" />
                <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" :options="warehouseOptions" :placeholder="t('Select')" :disabled="!editable" :error="form.errors.warehouse_id" />
                <TextInput :model-value="record.currency ?? '-'" :label="t('Currency')" dir="ltr" disabled />
                <TextInput :model-value="record.exchange_rate" :label="t('Exchange Rate')" dir="ltr" disabled />
            </div>
        </Card>

        <Card
            :title="t(':document Items', { document: t('Sales Return') })"
            :description="t('Enter the returned quantity of each item and the returned pieces of each sub-item; prices come from the invoice.')"
        >
            <ItemsGrid v-model:items="form.items" v-model:totals="totals" :config="gridConfig" :context="gridContext" :error="itemsError" />
        </Card>

        <div class="grid gap-4 lg:grid-cols-3">
            <DocumentTotals :totals="totals" :currency="record.currency">
                <template #discount>
                    <!-- The return's share of the invoice discount, computed when saving. -->
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-muted">{{ t('Return Discount') }}</span>
                        <span class="tabular-nums font-medium" dir="ltr">{{ record.invoice_discount }}</span>
                    </div>
                </template>
            </DocumentTotals>
            <Card class="lg:col-span-2" :title="t('Notes')">
                <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="8" :disabled="!editable" :error="form.errors.notes" />
            </Card>
        </div>

        <FormActions v-if="editable" :processing="form.processing" :cancel-href="route('accounting.sales-returns.index')" :submit-label="t('Save Draft')" />
    </form>

    <PaymentAllocationPanel v-if="allocationPanel" class="mt-4" :panel="allocationPanel" />
</template>

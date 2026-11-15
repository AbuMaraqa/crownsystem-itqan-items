<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import FormActions from '@/Components/form/FormActions.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { CircleCheck, Layers, Package, Printer, ShoppingCart, Trash2, Truck, X } from '@/lib/icons';
import { route } from '@/lib/route';

import SalesOrderFields from './SalesOrderFields.vue';
import type { SalesOrderForm, SalesOrderOptions, SalesOrderRecord } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<SalesOrderOptions & { order: SalesOrderForm; record: SalesOrderRecord }>();

const form = useForm<SalesOrderForm>(props.order);

// After a save the server sends the lines again with their ids: the form starts from them, or the
// next save would delete and recreate the new lines.
watch(
    () => props.order,
    (order) => {
        form.defaults(order);
        form.reset();
    },
);

const id = computed(() => props.record.id);
const editable = computed(() => props.record.editable);
/** Every action saves the open changes first, as the old page did. */
const savesFirst = computed(() => editable.value && can('Sales Order Update'));

function act(name: string): void {
    const url = route(`accounting.sales-orders.${name}`, id.value);

    if (savesFirst.value) form.post(url, { preserveScroll: true });
    else router.post(url, {}, { preserveScroll: true });
}

async function confirmOrder(): Promise<void> {
    if (await confirm({ title: t('Confirm this sales order?'), confirmLabel: t('Confirm'), tone: 'primary' })) act('confirm');
}

async function prepare(): Promise<void> {
    if (await confirm({ title: t('Send this sales order to preparation?'), confirmLabel: t('Send To Preparation'), tone: 'primary' })) act('prepare');
}

async function invoice(): Promise<void> {
    if (await confirm({ title: t('Create a sales invoice from this order?'), confirmLabel: t('Create Sales Invoice'), tone: 'primary' })) act('invoice');
}

async function cancel(): Promise<void> {
    if (await confirm({ title: t('Cancel this sales order?'), confirmLabel: t('Cancel Order') })) {
        router.post(route('accounting.sales-orders.cancel', id.value), {}, { preserveScroll: true });
    }
}

async function remove(): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: props.record.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.sales-orders.destroy', id.value));
    }
}

const quotationOption = computed(() => (props.record.sales_quotation ? { value: props.record.sales_quotation.id, label: props.record.sales_quotation.reference_no } : null));
const linkClass = 'font-semibold underline underline-offset-2';
</script>

<template>
    <PageHeader :title="`${t('Sales Order')} ${record.reference_no}`" :parents="[{ title: t('Sales Orders'), href: route('accounting.sales-orders.index') }]">
        <template #actions>
            <Badge v-if="record.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge>
            <Button v-if="record.can_confirm && can('Sales Order Confirm')" variant="primary" :loading="form.processing" @click="confirmOrder"><CircleCheck />{{ t('Confirm') }}</Button>
            <Button v-if="record.can_prepare && can('Sales Order Prepare')" variant="light" :loading="form.processing" @click="prepare"><Package />{{ t('Send To Preparation') }}</Button>
            <Button v-if="record.can_invoice && can('Sales Invoice Create')" variant="light" :loading="form.processing" @click="invoice"><ShoppingCart />{{ t('Create Sales Invoice') }}</Button>
            <Button v-if="record.can_produce && can('Production Order Create')" external :href="route('accounting.production-orders.add', { sales_order_id: id })"><Layers />{{ t('Create Production Order') }}</Button>
            <Button v-if="record.delivery.can_deliver && can('Delivery Note Create')" external :href="route('accounting.inventory.delivery-notes.add', { sales_order: id })"><Truck />{{ t('Deliver From Warehouse') }}</Button>
            <Button v-if="editable && can('Sales Order Cancel')" variant="danger" @click="cancel"><X />{{ t('Cancel') }}</Button>
            <Button v-if="can('Sales Order View')" external new-tab :href="route('accounting.sales-orders.pdf', id)"><Printer />{{ t('Print') }}</Button>
            <Button v-if="record.can_delete && can('Sales Order Delete')" variant="ghost" :aria-label="t('Delete')" @click="remove"><Trash2 /></Button>
        </template>
    </PageHeader>

    <div class="mb-4 space-y-3 empty:hidden">
        <div v-if="record.sales_quotation" class="rounded-xl border border-blue-light-200 bg-blue-light-50 px-4 py-3 text-sm font-medium text-blue-light-800 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-300">
            {{ t('This sales order was created from sales quotation') }}
            <Link v-if="can('Sales Quotation Update')" :class="linkClass" :href="route('accounting.sales-quotations.edit', record.sales_quotation.id)" dir="ltr">{{ record.sales_quotation.reference_no }}</Link>
            <span v-else class="font-semibold" dir="ltr">{{ record.sales_quotation.reference_no }}</span>
        </div>

        <div v-if="record.sales_invoices.length" class="rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-sm font-medium text-success-800 dark:border-success-500/25 dark:bg-success-500/10 dark:text-success-300">
            {{ t('Sales invoices for this order') }}:
            <span v-for="(item, index) in record.sales_invoices" :key="item.id">
                <a v-if="can('Sales Invoice View List')" :class="linkClass" :href="route('accounting.sales-invoices.edit', item.id)" dir="ltr">{{ item.reference_no }}</a>
                <span v-else class="font-semibold" dir="ltr">{{ item.reference_no }}</span>
                <span v-if="item.status" class="text-xs"> ({{ item.status.label }})</span><span v-if="index < record.sales_invoices.length - 1" class="mx-1">·</span>
            </span>
        </div>

        <div v-if="record.production_orders.length" class="rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm font-medium text-brand-800 dark:border-brand-500/25 dark:bg-brand-500/10 dark:text-brand-300">
            {{ t('Production orders for this sales order') }}:
            <span v-for="(production, index) in record.production_orders" :key="production.id">
                <a :class="linkClass" :href="route('accounting.production-orders.edit', production.id)" dir="ltr">{{ production.reference_no }}</a>
                <span class="text-xs"> ({{ production.status }} - {{ t('Total Cost') }}: <span dir="ltr">{{ production.total_cost }}</span>)</span><span v-if="index < record.production_orders.length - 1" class="mx-1">·</span>
            </span>
        </div>

        <div
            v-if="record.direct_supply"
            class="flex flex-col gap-3 rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm font-medium text-warning-800 md:flex-row md:items-center md:justify-between dark:border-warning-500/25 dark:bg-warning-500/10 dark:text-warning-300"
        >
            <div class="space-y-1">
                <p>
                    {{ t('This sales order is supplied directly by a supplier') }}<template v-if="record.direct_supply.supplier">: <span class="font-semibold">{{ record.direct_supply.supplier }}</span></template>.
                    <template v-if="record.direct_supply.is_draft">{{ t('It will be sent to the purchasing department when confirmed.') }}</template>
                    <template v-else-if="record.direct_supply.notified_at">{{ t('Sent to the purchasing department on :date.', { date: record.direct_supply.notified_at }) }}</template>
                </p>
                <p v-if="record.direct_supply.purchase_orders.length">
                    {{ t('Purchase orders for this sales order') }}:
                    <span v-for="(purchase, index) in record.direct_supply.purchase_orders" :key="purchase.id">
                        <a v-if="can('Purchase Order Update')" :class="linkClass" :href="route('accounting.purchase-orders.edit', purchase.id)" dir="ltr">{{ purchase.reference_no }}</a>
                        <span v-else class="font-semibold" dir="ltr">{{ purchase.reference_no }}</span>
                        <span class="text-xs"> ({{ purchase.supplier }} - {{ purchase.status }})</span><span v-if="index < record.direct_supply.purchase_orders.length - 1" class="mx-1">·</span>
                    </span>
                </p>
                <p v-else-if="record.direct_supply.awaiting" class="text-xs">{{ t('Awaiting a purchase order from the purchasing department.') }}</p>
            </div>
            <Button
                v-if="record.direct_supply.can_create_purchase_order && can('Purchase Order Create')"
                class="shrink-0"
                external
                :href="route('accounting.purchase-orders.add', { sales_order_id: id })"
            >
                <ShoppingCart />{{ t('Create Purchase Order') }}
            </Button>
        </div>

        <div v-if="record.delivery.is_direct" class="inline-flex items-center gap-1.5 rounded-lg bg-warning-50 px-3 py-2 text-xs font-medium text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
            <Truck class="size-4" />{{ t('Shipped directly by the supplier') }}
        </div>
        <div v-else-if="record.delivery.notes.length" class="flex flex-wrap items-center gap-1.5 text-xs">
            <span class="font-semibold text-muted">{{ t('Delivered') }} <span dir="ltr">{{ record.delivery.percent }}%</span>:</span>
            <a
                v-for="note in record.delivery.notes"
                :key="note.id"
                :href="route('accounting.inventory.delivery-notes.edit', note.id)"
                class="rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-gray-700 hover:bg-brand-50 hover:text-brand-700 dark:bg-white/5 dark:text-gray-300"
            >
                {{ note.reference_no }} <span class="font-sans text-[10px] text-muted">{{ note.status }}</span>
            </a>
        </div>
    </div>

    <form @submit.prevent="form.put(route('accounting.sales-orders.update', id), { preserveScroll: true })">
        <SalesOrderFields
            :form="form"
            :options="props"
            :reference-no="record.reference_no"
            :status-label="record.status?.label ?? ''"
            :client="record.client"
            :quotation="quotationOption"
            :direct-supplier="record.direct_supplier"
            :margin-hint="record.margin_hint"
            :readonly="!editable"
            :fulfillment-locked="record.fulfillment_locked"
        />
        <FormActions v-if="editable && can('Sales Order Update')" :processing="form.processing" :cancel-href="route('accounting.sales-orders.index')" :submit-label="t('Update')" />
    </form>
</template>

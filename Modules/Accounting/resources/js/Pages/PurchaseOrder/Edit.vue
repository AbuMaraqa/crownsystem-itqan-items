<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import type { ExistingImage } from '@/Components/form/ImageUpload.vue';
import FormActions from '@/Components/form/FormActions.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { CheckRead, CircleCheck, DollarSign, Inbox, Printer, Trash2, Truck, X } from '@/lib/icons';
import { route } from '@/lib/route';

import type { Option } from '../parts/types';
import PurchaseOrderFields from './PurchaseOrderFields.vue';
import type { PurchaseOrderForm, PurchaseOrderOptions, PurchaseOrderRecord } from './types';

defineOptions({ layout: AdminLayout });

type OrderValues = Omit<PurchaseOrderForm, 'attachments' | 'removed_attachments' | 'attachment_order'>;

const props = defineProps<
    PurchaseOrderOptions & {
        order: OrderValues;
        supplier: Option | null;
        customer: Option | null;
        attachments: ExistingImage[];
        record: PurchaseOrderRecord;
    }
>();

const blank = (order: OrderValues): PurchaseOrderForm => ({ ...order, attachments: [], removed_attachments: [], attachment_order: [] });
const form = useForm<PurchaseOrderForm>(blank(props.order));

// After a save the server sends the lines again with their ids: the form starts from them, or the
// next save would delete and recreate the new lines.
watch(
    () => props.order,
    (order) => {
        form.defaults(blank(order));
        form.reset();
    },
);

const id = computed(() => props.record.id);
const editable = computed(() => props.record.editable);

/** Files go as multipart, which only POST carries: the method travels as `_method`. */
function save(): void {
    if (form.attachments.length) {
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('accounting.purchase-orders.update', id.value), { preserveScroll: true });
    } else {
        form.transform((data) => data).put(route('accounting.purchase-orders.update', id.value), { preserveScroll: true });
    }
}

/** An action saves the open changes first, as the old page did. */
function act(name: string): void {
    const url = route(`accounting.purchase-orders.${name}`, id.value);

    if (editable.value) form.transform((data) => data).post(url, { preserveScroll: true });
    else router.post(url, {}, { preserveScroll: true });
}

async function cancel(): Promise<void> {
    if (await confirm({ title: t('Cancel this purchase order?'), confirmLabel: t('Cancel') })) {
        router.post(route('accounting.purchase-orders.cancel', id.value), {}, { preserveScroll: true });
    }
}

async function remove(): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: props.record.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.purchase-orders.destroy', id.value));
    }
}
</script>

<template>
    <PageHeader :title="`${t('Purchase Order')} ${record.reference_no}`" :parents="[{ title: t('Purchase Orders'), href: route('accounting.purchase-orders.index') }]">
        <template #actions>
            <Badge v-if="record.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge>
            <Button v-if="record.can_confirm && can('Purchase Order Confirm')" variant="primary" :loading="form.processing" @click="act('confirm')"><CheckRead />{{ t('Confirm') }}</Button>
            <Button v-if="record.quotation && can('Purchase Quotation View List')" :href="route('accounting.purchase-quotations.edit', record.quotation.id)">
                <DollarSign />{{ t('Purchase Quotation') }} <bdi>{{ record.quotation.reference_no }}</bdi>
                <Badge v-if="record.quotation.status" :tone="record.quotation.status.tone">{{ record.quotation.status.label }}</Badge>
            </Button>
            <Button v-else-if="record.can_create_quotation && can('Purchase Quotation Create')" variant="light" @click="act('quotation')"><DollarSign />{{ t('Create Purchase Quotation') }}</Button>
            <Button v-if="record.can_receive_goods && can('Goods Receipt Create')" variant="light" external :href="route('accounting.inventory.goods-receipts.add', { purchase_order: id })"><Inbox />{{ t('Receive Goods') }}</Button>
            <Button v-if="record.can_mark_received && can('Purchase Order Receive')" variant="light" @click="act('receive')"><Truck />{{ t('Mark Delivered to Customer') }}</Button>
            <Button v-if="record.can_invoice && can('Purchase Invoice Create')" variant="primary" @click="act('invoice')"><CircleCheck />{{ t('Create Purchase Invoice') }}</Button>
            <Button v-if="record.can_cancel && can('Purchase Order Cancel')" variant="danger" @click="cancel"><X />{{ t('Cancel') }}</Button>
            <Button v-if="can('Purchase Order View')" external new-tab :href="route('accounting.purchase-orders.pdf', id)"><Printer />{{ t('Print') }}</Button>
            <Button v-if="record.can_delete && can('Purchase Order Delete')" variant="ghost" :aria-label="t('Delete')" @click="remove"><Trash2 /></Button>
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3">
        <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted">
            <span>{{ record.supplier ?? '-' }}</span>
            <span dir="ltr">{{ record.order_date }}</span>
            <span>{{ record.items_count }} {{ t('Items') }}</span>
        </p>

        <!-- Direct shipment: the goods go from the supplier to the customer, not the warehouse. -->
        <div v-if="record.direct" class="flex flex-wrap items-center gap-2 rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-500/25 dark:bg-warning-500/10 dark:text-warning-300">
            <Truck class="size-4 shrink-0" />
            <span>{{ t('Direct shipment to customer') }}: {{ record.direct.customer ?? '-' }}</span>
            <template v-if="record.direct.sales_order">
                ·
                <a v-if="can('Sales Order View List')" :href="route('accounting.sales-orders.edit', record.direct.sales_order.id)" class="font-semibold underline"><bdi>{{ record.direct.sales_order.reference_no }}</bdi></a>
                <bdi v-else>{{ record.direct.sales_order.reference_no }}</bdi>
            </template>
            <span>— {{ t('The goods do not enter the warehouse.') }}</span>
        </div>

        <!-- Receiving into the warehouse: how much arrived and the goods receipts. -->
        <div v-else-if="record.receiving && record.receiving.receipts.length" class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-card px-4 py-3 text-sm">
            <span class="font-semibold">{{ t('Received') }}: <span dir="ltr">{{ record.receiving.percent }}%</span></span>
            <div class="h-2 w-40 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                <div class="h-full rounded-full bg-success-500" :style="{ width: `${Math.min(record.receiving.percent, 100)}%` }" />
            </div>
            <a
                v-for="receipt in record.receiving.receipts"
                :key="receipt.id"
                :href="route('accounting.inventory.goods-receipts.edit', receipt.id)"
                class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-0.5 text-gray-700 hover:bg-brand-50 dark:bg-white/10 dark:text-gray-200"
            >
                <bdi>{{ receipt.reference_no }}</bdi>
                <span class="text-[11px] text-muted">{{ receipt.status }}</span>
            </a>
        </div>
    </div>

    <form @submit.prevent="save">
        <PurchaseOrderFields
            :form="form"
            :options="props"
            :supplier="supplier"
            :customer="customer"
            :attachments="attachments"
            :readonly="!editable"
            :delivery-locked="record.delivery_locked"
        />
        <FormActions v-if="editable" :processing="form.processing" :cancel-href="route('accounting.purchase-orders.index')" :submit-label="t('Update')" />
        <p v-else-if="record.locked" class="mt-4 text-sm font-medium text-muted">{{ t('This order can no longer be edited.') }}</p>
    </form>
</template>

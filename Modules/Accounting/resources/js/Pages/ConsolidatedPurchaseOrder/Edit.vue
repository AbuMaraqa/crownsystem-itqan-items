<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { MessageDots, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

import ActivityTab from './tabs/ActivityTab.vue';
import AdditionalCostsTab from './tabs/AdditionalCostsTab.vue';
import AttachmentsTab from './tabs/AttachmentsTab.vue';
import AwardingTab from './tabs/AwardingTab.vue';
import ClearancesTab from './tabs/ClearancesTab.vue';
import DeliveriesTab from './tabs/DeliveriesTab.vue';
import FinancialFileTab from './tabs/FinancialFileTab.vue';
import InsurancesTab from './tabs/InsurancesTab.vue';
import ItemsTab from './tabs/ItemsTab.vue';
import NotesTab from './tabs/NotesTab.vue';
import PriceOffersTab from './tabs/PriceOffersTab.vue';
import QuoteRequestTab from './tabs/QuoteRequestTab.vue';
import ReappraisalsTab from './tabs/ReappraisalsTab.vue';
import ShipmentsTab from './tabs/ShipmentsTab.vue';
import type { CpoRecord, ItemRow, OrderItemChoice, TabOptions, TabRecord } from './types';

defineOptions({ layout: AdminLayout });

interface HeaderForm {
    order_date: string | null;
    expected_delivery_date: string | null;
    followed_up_by: number | null;
    notes: string | null;
}

const props = defineProps<
    TabOptions & {
        order: HeaderForm;
        record: CpoRecord;
        counts: Record<string, number>;
        items: { rows: ItemRow[]; has_weight_items: boolean };
        shipments: TabRecord[];
        insurances: TabRecord[];
        clearances: TabRecord[];
        additionalCosts: TabRecord[];
        reappraisals: TabRecord[];
        deliveries: TabRecord[];
        attachments: TabRecord[];
        notes: TabRecord[];
        priceOffers: TabRecord[];
        payments: TabRecord[];
        bankCredits: TabRecord[];
        orderItems: OrderItemChoice[];
        blankOfferItems: Record<string, unknown>[];
        activity: { created_by: string | null; created_at: string | null; updated_at: string | null; activities: never[] };
    }
>();

const form = useForm<HeaderForm>(props.order);

watch(
    () => props.order,
    (order) => {
        form.defaults(order);
        form.reset();
    },
);

/**
 * Hidden for now, as on the old page: price quotes, awarding, the financial file and the forms
 * (quote request). Their tabs work; remove a key here to show it.
 */
const HIDDEN = ['price_quotes', 'awarding', 'financial_file', 'forms'];

const tab = ref('items');
const id = computed(() => props.record.id);

/** A tab with records shows how many, so the staff see which tabs have data before opening them. */
const label = (text: string, key: string): string => {
    const count = props.counts[key] ?? 0;

    if (key === 'awarding') return count ? `${text} (${t('Awarded')})` : text;

    return count ? `${text} (${count})` : text;
};

const tabs = computed(() =>
    [
        { value: 'items', label: label(t('Items'), 'items') },
        { value: 'price_quotes', label: label(t('Price Quotes'), 'price_quotes') },
        { value: 'awarding', label: label(t('Awarding'), 'awarding') },
        { value: 'financial_file', label: label(t('Financial File'), 'financial_file') },
        { value: 'shipping', label: label(t('Shipping'), 'shipping') },
        { value: 'insurance', label: label(t('Insurance'), 'insurance') },
        { value: 'clearance', label: label(t('Customs Clearance'), 'clearance') },
        { value: 'additional_costs', label: label(t('Additional Costs'), 'additional_costs') },
        { value: 'reappraisal', label: label(t('Customs Reappraisal'), 'reappraisal') },
        { value: 'delivery', label: label(t('Delivery'), 'delivery') },
        { value: 'attachments', label: label(t('Attachments'), 'attachments') },
        { value: 'notes', label: label(t('Notes'), 'notes') },
        { value: 'forms', label: t('Forms') },
        { value: 'chats', label: t('Chats') },
        { value: 'activity_log', label: t('Activity Log') },
    ].filter((item) => !HIDDEN.includes(item.value)),
);

function save(): void {
    form.put(route('accounting.consolidated-purchase-orders.update', id.value), { preserveScroll: true, preserveState: true });
}

async function remove(): Promise<void> {
    if (await confirm({ title: t('Are you sure you want to delete this consolidated purchase order?'), description: props.record.reference_no })) {
        router.delete(route('accounting.consolidated-purchase-orders.destroy', id.value));
    }
}
</script>

<template>
    <PageHeader :title="`${t('Consolidated Purchase Order')} ${record.reference_no}`" :parents="[{ title: t('Consolidated Purchase Orders'), href: route('accounting.consolidated-purchase-orders.index') }]">
        <template #actions>
            <Badge v-if="record.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge>
            <Button v-if="can('Consolidated Purchase Order Delete')" variant="danger" @click="remove"><Trash2 />{{ t('Delete') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <form @submit.prevent="save">
        <Card :title="t('Consolidated Purchase Order Details')">
            <template v-if="can('Consolidated Purchase Order Update')" #actions>
                <Button type="submit" variant="primary" size="sm" :loading="form.processing">{{ t('Save') }}</Button>
            </template>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TextInput :model-value="record.reference_no" :label="t('Reference No')" dir="ltr" disabled />
                <TextInput v-model="form.order_date" type="date" :label="t('Order Date')" required :error="form.errors.order_date" />
                <TextInput v-model="form.expected_delivery_date" type="date" :label="t('Expected Delivery Date')" :error="form.errors.expected_delivery_date" />
                <RemoteSelect
                    v-model="form.followed_up_by"
                    :url="route('accounting.consolidated-purchase-orders.parties', { role: 'user' })"
                    :initial="record.followed_up_by ? [record.followed_up_by] : []"
                    :label="t('Followed Up By')"
                    :placeholder="t('Select')"
                    :error="form.errors.followed_up_by"
                />
                <TextInput :model-value="record.client ?? '-'" :label="t('Supplier')" disabled />
                <TextInput :model-value="record.currency ?? '-'" :label="t('Currency')" dir="ltr" disabled />
                <TextInput :model-value="record.total_amount" :label="t('Total Amount')" dir="ltr" disabled />
                <div class="sm:col-span-2 lg:col-span-4">
                    <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="2" :error="form.errors.notes" />
                </div>
            </div>
        </Card>
        </form>

        <Tabs v-model="tab" :tabs="tabs">
            <template #items><ItemsTab :order-id="id" :items="items" /></template>
            <template #price_quotes><PriceOffersTab :order-id="id" :currency-id="record.currency_id" :rows="priceOffers" :options="props" :blank-items="blankOfferItems" /></template>
            <template #awarding><AwardingTab :order-id="id" :rows="priceOffers" /></template>
            <template #financial_file><FinancialFileTab :order-id="id" :currency-id="record.currency_id" :payments="payments" :bank-credits="bankCredits" :options="props" /></template>
            <template #shipping><ShipmentsTab :order-id="id" :currency-id="record.currency_id" :rows="shipments" :options="props" /></template>
            <template #insurance><InsurancesTab :order-id="id" :rows="insurances" /></template>
            <template #clearance><ClearancesTab :order-id="id" :currency-id="record.currency_id" :rows="clearances" :options="props" /></template>
            <template #additional_costs><AdditionalCostsTab :order-id="id" :currency-id="record.currency_id" :rows="additionalCosts" :options="props" :order-items="orderItems" /></template>
            <template #reappraisal><ReappraisalsTab :order-id="id" :currency-id="record.currency_id" :rows="reappraisals" :options="props" :order-items="orderItems" /></template>
            <template #delivery><DeliveriesTab :order-id="id" :currency-id="record.currency_id" :rows="deliveries" :options="props" /></template>
            <template #attachments><AttachmentsTab :order-id="id" :rows="attachments" :options="props" /></template>
            <template #notes><NotesTab :order-id="id" :rows="notes" /></template>
            <template #forms><QuoteRequestTab :order-id="id" /></template>
            <template #chats>
                <Card>
                    <div class="flex flex-col items-center justify-center gap-2 py-8 text-center text-muted">
                        <MessageDots class="size-8" />
                        <p class="text-sm">{{ t('Coming soon') }}</p>
                    </div>
                </Card>
            </template>
            <template #activity_log><ActivityTab :activity="activity" /></template>
        </Tabs>

        <p v-if="record.created_by || record.followed_up_by" class="text-center text-sm text-danger">
            {{ t('This order was created by :creator', { creator: record.created_by ?? '—' }) }}
            <template v-if="record.followed_up_by"> - {{ t('and is being followed up by :follower', { follower: record.followed_up_by.label }) }}</template>
        </p>
    </div>
</template>

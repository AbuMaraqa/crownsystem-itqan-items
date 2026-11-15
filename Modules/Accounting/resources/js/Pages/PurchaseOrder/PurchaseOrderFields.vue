<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, ref, shallowRef, watch } from 'vue';

import ImageUpload, { type ExistingImage } from '@/Components/form/ImageUpload.vue';
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import { createGridApi } from '../parts/items-grid/api';
import ItemsGrid from '../parts/items-grid/ItemsGrid.vue';
import { money } from '../parts/items-grid/useItemsGrid';
import type { DocumentTotals as Totals } from '../parts/items-grid/types';
import type { Option } from '../parts/types';
import { DIRECT, DOCUMENT, type PurchaseOrderForm, type PurchaseOrderOptions } from './types';

/**
 * The purchase order form, shared by the add and edit pages: supplier, dates, warehouse and delivery
 * type (to the warehouse, or shipped by the supplier straight to a customer), the items grid with
 * groups, lengths and an optional price (an approved purchase quotation overrides it), the total,
 * notes and image attachments. The server computes every amount again when saving.
 */
const props = defineProps<{
    form: InertiaForm<PurchaseOrderForm>;
    options: PurchaseOrderOptions;
    supplier?: Option | null;
    customer?: Option | null;
    attachments?: ExistingImage[];
    readonly?: boolean;
    /** The delivery type stays once goods were received against the order. */
    deliveryLocked?: boolean;
}>();

const totals = ref<Totals>({ sub_total: 0, items_discount: 0, document_discount: 0, tax_amount: 0, total_amount: 0 });
const api = createGridApi(DOCUMENT, () => ({ partyId: props.form.client_id, exchangeRate: null }));
const salesOrders = shallowRef<Option[]>(props.options.salesOrderOptions);

const isDirect = computed(() => props.form.delivery_type === DIRECT);
const deliveryDisabled = computed(() => props.readonly || props.deliveryLocked);
const gridContext = computed(() => ({ partyId: props.form.client_id, exchangeRate: null, warehouseId: props.form.warehouse_id }));
const itemsError = computed(() => Object.entries(props.form.errors as Record<string, string>).find(([key]) => key === 'items' || key.startsWith('items.'))?.[1]);
const attachmentsError = computed(() => Object.entries(props.form.errors as Record<string, string>).find(([key]) => key.startsWith('attachments'))?.[1]);

watch(
    () => props.form.delivery_type,
    (type) => {
        if (type !== DIRECT) {
            props.form.direct_customer_id = null;
            props.form.direct_sales_order_id = null;
        }
    },
);

/** The sales orders the shipment can be for follow the chosen customer. */
watch(
    () => props.form.direct_customer_id,
    async (customer, previous) => {
        if (previous !== undefined && String(customer ?? '') !== String(previous ?? '')) props.form.direct_sales_order_id = null;

        try {
            const response = await fetch(route('accounting.purchase-orders.sales-orders', { customer_id: customer || undefined }), { headers: { Accept: 'application/json' } });
            if (response.ok) salesOrders.value = (await response.json()) as Option[];
        } catch {
            // The list stays as it is.
        }
    },
);
</script>

<template>
    <div class="grid gap-4">
        <Card :title="t('Order Info')" :description="t('Supplier, dates and where the goods are delivered. The price is optional; an approved purchase quotation overrides it.')">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="sm:col-span-2">
                    <TextInput v-if="readonly" :model-value="supplier?.label ?? '-'" :label="t('Supplier')" disabled />
                    <RemoteSelect
                        v-else
                        v-model="form.client_id"
                        :url="api.partiesUrl()"
                        :initial="supplier ? [supplier] : []"
                        :label="t('Supplier')"
                        :placeholder="t('Select a supplier')"
                        required
                        :error="form.errors.client_id"
                    />
                </div>
                <TextInput v-model="form.order_date" type="date" :label="t('Order Date')" required :disabled="readonly" :error="form.errors.order_date" />
                <TextInput v-model="form.expected_delivery_date" type="date" :label="t('Expected Delivery Date')" :disabled="readonly" :error="form.errors.expected_delivery_date" />
                <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" :options="options.warehouseOptions" :placeholder="t('Select a warehouse')" :disabled="readonly" :error="form.errors.warehouse_id" />
                <SelectInput
                    v-model="form.delivery_type"
                    :label="t('Delivery Type')"
                    :options="options.deliveryTypeOptions"
                    :hint="isDirect ? t('The supplier ships to the customer; the goods do not enter the warehouse.') : undefined"
                    :disabled="deliveryDisabled"
                    :error="form.errors.delivery_type"
                />
                <template v-if="isDirect">
                    <TextInput v-if="deliveryDisabled" :model-value="customer?.label ?? '-'" :label="t('Ship To Customer')" disabled />
                    <RemoteSelect
                        v-else
                        v-model="form.direct_customer_id"
                        :url="route('accounting.purchase-orders.customers')"
                        :initial="customer ? [customer] : []"
                        :label="t('Ship To Customer')"
                        :placeholder="t('Select')"
                        required
                        :error="form.errors.direct_customer_id"
                    />
                    <SelectInput
                        v-model="form.direct_sales_order_id"
                        :label="t('For Sales Order')"
                        :options="salesOrders"
                        :placeholder="t('Select')"
                        :disabled="deliveryDisabled"
                        :error="form.errors.direct_sales_order_id"
                    />
                </template>
            </div>
        </Card>

        <Card :title="t('Items List')" :description="t('Add items one by one, or pull in a whole category at once.')">
            <ItemsGrid
                v-model:items="form.items"
                v-model:totals="totals"
                :config="{ document: DOCUMENT, editable: !readonly, showLength: true }"
                :context="gridContext"
                :error="itemsError"
            />
            <div class="mt-4 flex justify-end">
                <div class="flex min-w-64 items-center justify-between gap-6 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2.5 dark:border-brand-500/25 dark:bg-brand-500/10">
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ t('Total Amount') }}</span>
                    <span class="tabular-nums text-base font-bold text-brand-700 dark:text-brand-300" dir="ltr">{{ money(totals.total_amount) }}</span>
                </div>
            </div>
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card :title="t('Notes')">
                <TextareaInput v-model="form.notes" :hint="t('Any note that should stay with this order.')" :rows="6" :disabled="readonly" :error="form.errors.notes" />
            </Card>
            <Card :title="t('Attachments')">
                <ImageUpload
                    v-if="!readonly"
                    :model-value="form.attachments"
                    @update:model-value="form.attachments = Array.isArray($event) ? $event : $event ? [$event] : []"
                    v-model:removed="form.removed_attachments"
                    v-model:order="form.attachment_order"
                    multiple
                    reorderable
                    :existing="attachments ?? []"
                    :error="attachmentsError"
                />
                <div v-else-if="attachments?.length" class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                    <a v-for="image in attachments" :key="image.id" :href="image.url" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-line">
                        <img :src="image.url" alt="" class="aspect-square w-full object-cover" />
                    </a>
                </div>
                <p v-else class="text-sm text-muted">{{ t('No attachments.') }}</p>
            </Card>
        </div>
    </div>
</template>

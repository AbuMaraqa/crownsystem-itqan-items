<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { controlClasses } from '@/Components/form/controlClasses';
import { t } from '@/lib/i18n';
import { Check, Plus, X } from '@/lib/icons';
import { route } from '@/lib/route';

import { getJson, HttpError, postJson, withQuery } from '../../parts/http';
import type { Option } from '../../parts/types';
import type { PickerItem, PulledRow } from '../types';
import MainItemSummary from './MainItemSummary.vue';
import SubItemsTable from './SubItemsTable.vue';

/**
 * "Add items from purchase order", on the add page (`rows`: the lines stay in the form until it is
 * saved) and in the items tab of the edit page (`orderId`: every pull is saved at once). Each
 * purchase order line is a main item with its sub-items to tick; a quantity item is added with its
 * quantity. The rules (what is left, what was pulled elsewhere, merging under one main item) are
 * ConsolidatedPurchaseOrderPickerService's.
 */
const props = defineProps<{ orderId?: number | null }>();

const open = defineModel<boolean>('open', { required: true });
const rows = defineModel<PulledRow[]>('rows', { default: () => [] });

const purchaseOrders = ref<Option[]>([]);
const poSearch = ref('');
const purchaseOrder = ref<Option | null>(null);
const items = ref<PickerItem[]>([]);
const itemSearch = ref('');
const lengthSearch = ref('');
const quantitySearch = ref('');
const selected = ref<string[]>([]);
const quantities = ref<Record<number, string>>({});
const loading = ref(false);
const busy = ref(false);
const notice = ref<{ tone: 'success' | 'error'; text: string } | null>(null);

const editMode = computed(() => !!props.orderId);
const searching = computed(() => !!(itemSearch.value || lengthSearch.value || quantitySearch.value));

let timer: ReturnType<typeof setTimeout> | undefined;
let request: AbortController | undefined;

function reset(): void {
    poSearch.value = '';
    purchaseOrder.value = null;
    items.value = [];
    itemSearch.value = '';
    lengthSearch.value = '';
    quantitySearch.value = '';
    selected.value = [];
    quantities.value = {};
    notice.value = null;
}

async function loadPurchaseOrders(): Promise<void> {
    try {
        purchaseOrders.value = await getJson<Option[]>(withQuery(route('accounting.consolidated-purchase-orders.picker.purchase-orders'), { q: poSearch.value, order: props.orderId }));
    } catch {
        purchaseOrders.value = [];
    }
}

async function loadItems(): Promise<void> {
    if (!purchaseOrder.value) return;

    request?.abort();
    request = new AbortController();
    loading.value = true;

    try {
        const result = await postJson<{ label: string | null; items: PickerItem[] }>(
            route('accounting.consolidated-purchase-orders.picker.items'),
            {
                purchase_order_id: purchaseOrder.value.value,
                order: props.orderId ?? null,
                rows: editMode.value ? [] : rows.value,
                q: itemSearch.value,
                length: lengthSearch.value,
                quantity: quantitySearch.value,
                selected: selected.value,
            },
            request.signal,
        );
        items.value = result.items;
        loading.value = false;
    } catch {
        // A newer search replaced this one.
    }
}

function debounced(load: () => void): void {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
}

watch(open, (isOpen) => {
    if (!isOpen) return;

    reset();
    loadPurchaseOrders();
});

watch(poSearch, () => debounced(loadPurchaseOrders));
watch([itemSearch, lengthSearch, quantitySearch], () => debounced(loadItems));

function choose(option: Option): void {
    purchaseOrder.value = option;
    // The ticks belong to the purchase order shown; they do not move to another one.
    selected.value = [];
    quantities.value = {};
    loadItems();
}

function change(): void {
    purchaseOrder.value = null;
    items.value = [];
    selected.value = [];
    quantities.value = {};
}

/** The header box ticks every sub-item of its main item that can still be added. */
function allTicked(item: PickerItem): boolean {
    return item.selectable_keys.length > 0 && item.selectable_keys.every((key) => selected.value.includes(key));
}

function tickAll(item: PickerItem, checked: boolean): void {
    selected.value = checked ? [...new Set([...selected.value, ...item.selectable_keys])] : selected.value.filter((key) => !item.selectable_keys.includes(key));
}

async function pull(body: { keys?: string[]; purchase_order_item_id?: number; quantity?: string | null }, after: () => void): Promise<void> {
    busy.value = true;
    notice.value = null;

    if (editMode.value) {
        router.post(route('accounting.consolidated-purchase-orders.items.pull', props.orderId!), body, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                after();
                loadItems();
                loadPurchaseOrders();
            },
            onFinish: () => (busy.value = false),
        });

        return;
    }

    try {
        const result = await postJson<{ rows: PulledRow[]; added: number; skipped: number; message: string }>(route('accounting.consolidated-purchase-orders.picker.rows'), { rows: rows.value, ...body });

        rows.value = result.rows;
        notice.value = { tone: result.added > 0 ? 'success' : 'error', text: result.message };
        after();
        await loadItems();
    } catch (error) {
        notice.value = { tone: 'error', text: error instanceof HttpError ? error.firstMessage() : t('Something went wrong') };
    } finally {
        busy.value = false;
    }
}

function addItem(item: PickerItem): void {
    pull({ purchase_order_item_id: item.id, quantity: quantities.value[item.id] || null }, () => {
        delete quantities.value[item.id];
    });
}

function addSelected(): void {
    pull({ keys: selected.value }, () => (selected.value = []));
}

const quantity = (value: number): string => value.toLocaleString('en', { maximumFractionDigits: 3 });

onBeforeUnmount(() => {
    clearTimeout(timer);
    request?.abort();
});
</script>

<template>
    <Modal v-model:open="open" :title="t('Add Items from Purchase Order')" width="max-w-5xl">
        <div class="grid gap-4">
            <div>
                <p class="mb-1.5 text-sm font-medium">{{ t('Purchase Order') }}</p>
                <div v-if="purchaseOrder" class="flex items-center justify-between rounded-lg border border-brand-300 bg-brand-50 px-3 py-2 text-sm dark:border-brand-500/30 dark:bg-brand-500/10">
                    <span class="font-medium">{{ purchaseOrder.label }}</span>
                    <button type="button" class="text-muted hover:text-danger" :title="t('Change purchase order')" :aria-label="t('Change purchase order')" @click="change"><X class="size-4" /></button>
                </div>
                <template v-else>
                    <input v-model="poSearch" type="search" :placeholder="t('Search by reference or client...')" :class="[controlClasses, 'h-control']" />
                    <div class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-line">
                        <button
                            v-for="option in purchaseOrders"
                            :key="option.value"
                            type="button"
                            class="block w-full border-b border-line px-3 py-2 text-start text-sm last:border-0 hover:bg-brand-50 hover:text-brand-700 dark:hover:bg-brand-500/10 dark:hover:text-brand-300"
                            @click="choose(option)"
                        >
                            {{ option.label }}
                        </button>
                        <p v-if="!purchaseOrders.length" class="px-3 py-3 text-sm text-muted">{{ t('No purchase orders found.') }}</p>
                    </div>
                </template>
            </div>

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <input v-model="itemSearch" type="search" :disabled="!purchaseOrder" :placeholder="t('Search by main item, sub-item or barcode...')" :class="[controlClasses, 'col-span-2 h-control']" />
                <input v-model="lengthSearch" type="number" step="0.001" min="0" dir="ltr" :disabled="!purchaseOrder" :placeholder="t('Search by length')" :title="t('Sub item length')" :class="[controlClasses, 'h-control']" />
                <input v-model="quantitySearch" type="number" step="0.001" min="0" dir="ltr" :disabled="!purchaseOrder" :placeholder="t('Search by quantity')" :title="t('Sub item quantity')" :class="[controlClasses, 'h-control']" />
            </div>

            <p v-if="notice" class="rounded-lg px-3 py-2 text-sm" :class="notice.tone === 'success' ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400'" role="status">
                {{ notice.text }}
            </p>

            <div class="max-h-[28rem] space-y-2 overflow-y-auto rounded-lg border border-line p-2" :class="{ 'opacity-60': loading }">
                <div v-for="(item, index) in items" :key="`${item.id}-${item.selectable_keys.join(',')}`" class="overflow-hidden rounded-lg border border-brand-200 dark:border-brand-500/30" :class="{ 'opacity-60': item.already_added }">
                    <div class="flex flex-wrap items-center gap-3 bg-brand-50/60 px-3 py-2 text-sm dark:bg-brand-500/10">
                        <input
                            v-if="item.picks_sub_items && item.selectable_keys.length"
                            type="checkbox"
                            class="size-4 shrink-0 cursor-pointer rounded border-gray-300 text-brand-500 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-800"
                            :checked="allTicked(item)"
                            :title="t('Select all sub-items')"
                            :aria-label="t('Select all sub-items')"
                            @change="tickAll(item, ($event.target as HTMLInputElement).checked)"
                        />
                        <MainItemSummary :main="item.main" :number="index + 1" />
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-muted">{{ item.measure }}</span>
                            <Check v-if="item.already_added" class="size-5 shrink-0 text-success" :aria-label="t('Already added')" />
                            <template v-else-if="!item.picks_sub_items">
                                <input
                                    v-if="!item.is_weight_priced"
                                    v-model="quantities[item.id]"
                                    type="number"
                                    step="0.001"
                                    min="0.001"
                                    :max="item.remaining"
                                    dir="ltr"
                                    :placeholder="quantity(item.remaining)"
                                    :title="t('Quantity to pull (leave empty for all)')"
                                    :class="[controlClasses, 'h-8 w-28']"
                                />
                                <Button size="sm" :loading="busy" @click="addItem(item)"><Plus />{{ t('Add') }}</Button>
                            </template>
                        </div>
                    </div>
                    <SubItemsTable
                        v-if="item.main.sub_items.length"
                        v-model:selected="selected"
                        :sub-items="item.main.sub_items"
                        :number="index + 1"
                        :matched="item.matched"
                        :selection="item.picks_sub_items ? item.sub_item_keys : null"
                    />
                </div>
                <p v-if="!items.length && !loading" class="px-3 py-3 text-sm text-muted">
                    <template v-if="!purchaseOrder">{{ t('Select a purchase order to see its items.') }}</template>
                    <template v-else-if="searching">{{ t('No items match your search.') }}</template>
                    <template v-else>{{ t('This purchase order has no items.') }}</template>
                </p>
            </div>
        </div>

        <template #footer>
            <Button v-if="purchaseOrder" variant="primary" :loading="busy" :disabled="!selected.length" @click="addSelected">
                <Plus />{{ t('Add Selected') }}
                <span class="rounded bg-white/20 px-1.5 font-mono text-xs">{{ selected.length }}</span>
            </Button>
            <Button @click="open = false">{{ t('Close') }}</Button>
        </template>
    </Modal>
</template>

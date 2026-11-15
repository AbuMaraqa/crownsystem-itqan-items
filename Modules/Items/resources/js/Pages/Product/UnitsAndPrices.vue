<script setup lang="ts">
/**
 * An item's equivalent units, extra barcodes and its prices in the price lists. The services check
 * everything (ProductUnitService, ProductBarcodeService, ProductPriceService); this page shows and sends.
 */
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { controlClasses } from '@/Components/form/controlClasses';
import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

defineOptions({ layout: AdminLayout });

interface UnitRow {
    id: number;
    unit_id: number;
    unit: string;
    factor: string;
}

interface BarcodeRow {
    id: number;
    barcode: string;
    product_unit_id: number | null;
    unit: string;
    pack_quantity: string;
    is_prefix: boolean;
}

interface PriceCell {
    sell_price: string | number;
    buy_price: string | number;
    source_price_list_id: number | string | null;
    source_factor: string | number;
}

const props = defineProps<{
    product: { id: number; sku: string; name: string; barcode: string | null; base_unit: string };
    units: UnitRow[];
    barcodes: BarcodeRow[];
    priceLists: { id: number; name: string; same_buy_sell: boolean }[];
    /** The base unit (id 0), then the equivalent units. */
    unitRows: { id: number; label: string }[];
    prices: Record<string, Record<string, PriceCell>>;
    calculated: Record<string, Record<string, string>>;
    unitOptions: Option[];
    notice: string | null;
}>();

const page = usePage();
const allErrors = computed(() => Object.values(page.props.errors ?? {}).filter(Boolean) as string[]);

const cell = 'h-9 py-1';
const control = [controlClasses, cell];

/* ---------- units ---------- */

const editingUnit = ref<number | null>(null);
const unitForm = useForm({ unit_id: '' as number | string, factor: '' as number | string });

function editUnit(row: UnitRow): void {
    editingUnit.value = row.id;
    unitForm.unit_id = row.unit_id;
    unitForm.factor = row.factor;
}

function resetUnit(): void {
    editingUnit.value = null;
    unitForm.reset();
    unitForm.clearErrors();
}

function saveUnit(): void {
    const options = { preserveScroll: true, onSuccess: resetUnit };

    if (editingUnit.value) unitForm.put(route('products.units-and-prices.units.update', [props.product.id, editingUnit.value]), options);
    else unitForm.post(route('products.units-and-prices.units.store', props.product.id), options);
}

async function deleteUnit(row: UnitRow): Promise<void> {
    if (await confirm({ title: t('Delete this unit and its prices?') })) {
        router.delete(route('products.units-and-prices.units.destroy', [props.product.id, row.id]), { preserveScroll: true, onSuccess: resetUnit });
    }
}

/* ---------- barcodes ---------- */

const editingBarcode = ref<number | null>(null);
const barcodeForm = useForm({ barcode: '', product_unit_id: '' as number | string | null, pack_quantity: 1 as number | string, is_prefix: false });

function editBarcode(row: BarcodeRow): void {
    editingBarcode.value = row.id;
    barcodeForm.barcode = row.barcode;
    barcodeForm.product_unit_id = row.product_unit_id ?? '';
    barcodeForm.pack_quantity = row.pack_quantity;
    barcodeForm.is_prefix = row.is_prefix;
}

function resetBarcode(): void {
    editingBarcode.value = null;
    barcodeForm.reset();
    barcodeForm.clearErrors();
}

function saveBarcode(): void {
    const options = { preserveScroll: true, onSuccess: resetBarcode };

    if (editingBarcode.value) barcodeForm.put(route('products.units-and-prices.barcodes.update', [props.product.id, editingBarcode.value]), options);
    else barcodeForm.post(route('products.units-and-prices.barcodes.store', props.product.id), options);
}

async function deleteBarcode(row: BarcodeRow): Promise<void> {
    if (await confirm({ title: t('Delete this barcode?') })) {
        router.delete(route('products.units-and-prices.barcodes.destroy', [props.product.id, row.id]), { preserveScroll: true, onSuccess: resetBarcode });
    }
}

/* ---------- prices ---------- */

const priceForm = useForm({ prices: JSON.parse(JSON.stringify(props.prices)) as Record<string, Record<string, PriceCell>> });

function savePrices(): void {
    priceForm.put(route('products.units-and-prices.prices.update', props.product.id), {
        preserveScroll: true,
        onSuccess: () => {
            priceForm.defaults({ prices: JSON.parse(JSON.stringify(props.prices)) });
            priceForm.reset();
        },
    });
}
</script>

<template>
    <PageHeader :title="t('Units, Barcodes and Prices')" :parents="[{ title: t('Products List'), href: route('products.index') }]" />

    <div class="grid gap-4">
        <Card>
            <h2 class="text-base font-semibold">
                <span dir="ltr">{{ product.sku }}</span> - {{ product.name }}
            </h2>
            <p class="mt-1 text-sm text-muted">{{ t('Base Unit') }}: {{ product.base_unit }}</p>
            <p v-if="notice" class="mt-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700 dark:border-warning-500/25 dark:bg-warning-500/10 dark:text-warning-400">{{ notice }}</p>
            <ul v-if="allErrors.length" class="mt-3 list-inside list-disc text-sm text-danger" role="alert">
                <li v-for="(message, index) in allErrors" :key="index">{{ message }}</li>
            </ul>
        </Card>

        <!-- Equivalent units -->
        <Card :title="t('Equivalent Units')">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs text-muted">
                            <th class="px-3 py-2 text-start font-medium">{{ t('Unit') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ t('Base Units in This Unit') }}</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in units" :key="row.id" class="border-b border-line">
                            <td class="px-3 py-2">{{ row.unit }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ row.factor }}</td>
                            <td class="space-x-2 px-3 py-2 whitespace-nowrap rtl:space-x-reverse">
                                <Button size="sm" variant="ghost" @click="editUnit(row)">{{ t('Edit') }}</Button>
                                <Button size="sm" variant="ghost" class="text-danger" @click="deleteUnit(row)">{{ t('Delete') }}</Button>
                            </td>
                        </tr>
                        <tr class="bg-surface">
                            <td class="px-3 py-2">
                                <select v-model="unitForm.unit_id" :aria-label="t('Unit')" :class="control">
                                    <option value="">{{ t('Select') }}</option>
                                    <option v-for="option in unitOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <input v-model="unitForm.factor" type="text" inputmode="decimal" placeholder="12" :aria-label="t('Base Units in This Unit')" :class="control" />
                            </td>
                            <td class="space-x-2 px-3 py-2 whitespace-nowrap rtl:space-x-reverse">
                                <Button size="sm" variant="primary" :loading="unitForm.processing" @click="saveUnit">{{ editingUnit ? t('Save') : t('Add') }}</Button>
                                <Button v-if="editingUnit" size="sm" variant="ghost" @click="resetUnit">{{ t('Cancel') }}</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- Extra barcodes -->
        <Card :title="t('Additional Barcodes')">
            <p class="mb-3 text-xs text-muted">
                {{ t('Main barcode') }}: <bdi>{{ product.barcode || '-' }}</bdi>. {{ t('A leading * makes the barcode match anything that starts with it.') }}
            </p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs text-muted">
                            <th class="px-3 py-2 text-start font-medium">{{ t('Barcode') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ t('Unit') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ t('Pack Quantity') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ t('Prefix') }}</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in barcodes" :key="row.id" class="border-b border-line">
                            <td class="px-3 py-2 font-mono" dir="ltr">{{ row.barcode }}</td>
                            <td class="px-3 py-2">{{ row.unit }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ row.pack_quantity }}</td>
                            <td class="px-3 py-2">{{ row.is_prefix ? t('Yes') : t('No') }}</td>
                            <td class="space-x-2 px-3 py-2 whitespace-nowrap rtl:space-x-reverse">
                                <Button size="sm" variant="ghost" @click="editBarcode(row)">{{ t('Edit') }}</Button>
                                <Button size="sm" variant="ghost" class="text-danger" @click="deleteBarcode(row)">{{ t('Delete') }}</Button>
                            </td>
                        </tr>
                        <tr class="bg-surface">
                            <td class="px-3 py-2"><input v-model="barcodeForm.barcode" type="text" dir="ltr" :aria-label="t('Barcode')" :class="control" /></td>
                            <td class="px-3 py-2">
                                <select v-model="barcodeForm.product_unit_id" :aria-label="t('Unit')" :class="control">
                                    <option value="">{{ product.base_unit }}</option>
                                    <option v-for="row in units" :key="row.id" :value="row.id">{{ row.unit }}</option>
                                </select>
                            </td>
                            <td class="px-3 py-2"><input v-model="barcodeForm.pack_quantity" type="text" inputmode="decimal" :aria-label="t('Pack Quantity')" :class="control" /></td>
                            <td class="px-3 py-2"><input v-model="barcodeForm.is_prefix" type="checkbox" :aria-label="t('Prefix')" class="size-4 rounded accent-brand-500" /></td>
                            <td class="space-x-2 px-3 py-2 whitespace-nowrap rtl:space-x-reverse">
                                <Button size="sm" variant="primary" :loading="barcodeForm.processing" @click="saveBarcode">{{ editingBarcode ? t('Save') : t('Add') }}</Button>
                                <Button v-if="editingBarcode" size="sm" variant="ghost" @click="resetBarcode">{{ t('Cancel') }}</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- Prices in the price lists -->
        <Card :title="t('Price Lists')">
            <p class="mb-3 text-xs text-muted">{{ t('With a source list, the price is the same item and unit in that list times the factor.') }}</p>
            <p v-if="!priceLists.length" class="text-sm text-muted">{{ t('No price lists found') }}</p>
            <template v-else>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs text-muted">
                                <th class="px-3 py-2 text-start font-medium">{{ t('Price List') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ t('Unit') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ t('Sell Price') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ t('Buy Price') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ t('Source List') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ t('Factor') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ t('Calculated Price') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="list in priceLists" :key="list.id">
                                <tr v-for="unit in unitRows" :key="`${list.id}-${unit.id}`" class="border-b border-line">
                                    <td class="px-3 py-2">{{ list.name }}</td>
                                    <td class="px-3 py-2">{{ unit.label }}</td>
                                    <td class="min-w-28 px-3 py-2">
                                        <input v-model="priceForm.prices[list.id][unit.id].sell_price" type="text" inputmode="decimal" :aria-label="`${list.name} - ${unit.label} - ${t('Sell Price')}`" :class="control" />
                                    </td>
                                    <td class="min-w-28 px-3 py-2">
                                        <!-- A "buy = sell" list: the buy price follows the sell price when saved. -->
                                        <input
                                            v-model="priceForm.prices[list.id][unit.id].buy_price"
                                            type="text"
                                            inputmode="decimal"
                                            :disabled="list.same_buy_sell"
                                            :aria-label="`${list.name} - ${unit.label} - ${t('Buy Price')}`"
                                            :class="control"
                                        />
                                    </td>
                                    <td class="min-w-36 px-3 py-2">
                                        <select v-model="priceForm.prices[list.id][unit.id].source_price_list_id" :aria-label="`${list.name} - ${unit.label} - ${t('Source List')}`" :class="control">
                                            <option :value="null">{{ t('None') }}</option>
                                            <option v-for="source in priceLists.filter((other) => other.id !== list.id)" :key="source.id" :value="source.id">{{ source.name }}</option>
                                        </select>
                                    </td>
                                    <td class="min-w-24 px-3 py-2">
                                        <input v-model="priceForm.prices[list.id][unit.id].source_factor" type="text" inputmode="decimal" :aria-label="`${list.name} - ${unit.label} - ${t('Factor')}`" :class="control" />
                                    </td>
                                    <td class="px-3 py-2 font-mono whitespace-nowrap" dir="ltr">{{ calculated[list.id]?.[unit.id] ?? '' }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 flex justify-end">
                    <Button variant="primary" :loading="priceForm.processing" @click="savePrices">{{ t('Save') }}</Button>
                </div>
            </template>
        </Card>
    </div>
</template>

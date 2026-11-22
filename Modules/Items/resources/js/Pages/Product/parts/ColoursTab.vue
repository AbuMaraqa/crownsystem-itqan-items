<script setup lang="ts">
/**
 * The colours of a weight item. Each chosen colour is one variation: the same profile in that colour,
 * priced by the kg price of its price group (category × colour). The colours come from the item's
 * categories; the server names each row «item - colour», copies the item's measures into it and adds
 * the colour value the store reads (ProductService::syncVariations).
 */
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import Alert from '@/Components/ui/Alert.vue';
import Button from '@/Components/ui/Button.vue';
import Switch from '@/Components/ui/Switch.vue';
import { can, t } from '@/lib/i18n';
import { Check, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

import { emptyRow } from './productForm';
import type { ColourCatalogue, ColourChoice, ProductForm, VariantStock, VariationRow } from './types';
import { kgPrice } from './useCategoryColours';

const props = defineProps<{
    form: InertiaForm<ProductForm>;
    catalogue: ColourCatalogue;
    loading: boolean;
    failed: string | null;
    stock: Record<string, VariantStock>;
}>();

const errors = computed(() => props.form.errors as Record<string, string | undefined>);
const hasCategory = computed(() => props.form.category_id.length > 0);
const firstList = computed(() => props.catalogue.lists[0] ?? null);
const colourOf = (id: number | null): ColourChoice | undefined => props.catalogue.colours.find((colour) => colour.id === id);
const isChosen = (colour: ColourChoice): boolean => props.form.variations.some((row) => row.category_finish_id === colour.id);
const categoryUrl = computed(() => (hasCategory.value && can('Category Update') ? route('categories.edit', props.form.category_id[0]) : null));

/** Rows the user unticked, so ticking the colour again brings back their edits (and the saved id). */
const removed = new Map<number, VariationRow>();

/** Positions change with the rows, so errors keyed by position no longer point at the right one. */
function setRows(rows: VariationRow[]): void {
    const stale = Object.keys(errors.value).filter((key) => key.startsWith('variations'));

    if (stale.length) props.form.clearErrors(...(stale as never[]));

    props.form.variations = rows;
}

function newRow(colour: ColourChoice): VariationRow {
    return { ...emptyRow(), id: null, attribute_values: [], category_finish_id: colour.id, is_active: true, name: '', slug: '' };
}

function toggle(colour: ColourChoice): void {
    const current = props.form.variations.find((row) => row.category_finish_id === colour.id);

    if (current) {
        removed.set(colour.id, current);
        setRows(props.form.variations.filter((row) => row !== current));

        return;
    }

    setRows([...props.form.variations, removed.get(colour.id) ?? newRow(colour)]);
}

function chooseAll(): void {
    setRows([...props.form.variations, ...props.catalogue.colours.filter((colour) => colour.is_active && !isChosen(colour)).map((colour) => removed.get(colour.id) ?? newRow(colour))]);
}

function removeRow(index: number): void {
    const row = props.form.variations[index];

    if (row.category_finish_id !== null) removed.set(row.category_finish_id, row);
    setRows(props.form.variations.filter((_, position) => position !== index));
}

/** What the server will call the row. */
const rowName = (row: VariationRow): string => [props.form.name, colourOf(row.category_finish_id)?.name].filter(Boolean).join(' - ');
const rowError = (index: number, field: string): string | undefined => errors.value[`variations.${index}.${field}`];
</script>

<template>
    <div class="grid gap-5">
        <Alert v-if="!hasCategory">{{ t("Choose the item's category first: its colours and their kg prices come from the category.") }}</Alert>
        <Alert v-else-if="failed" tone="danger">{{ failed }}</Alert>
        <p v-else-if="loading && !catalogue.colours.length" class="text-sm text-muted">{{ t('Loading colours…') }}</p>
        <Alert v-else-if="!catalogue.colours.length" tone="warning">
            {{ t('This category has no colours yet. Add them with their kg prices on the category page.') }}
            <a v-if="categoryUrl" :href="categoryUrl" class="ms-1 font-medium underline">{{ t('Open the category') }}</a>
        </Alert>

        <section v-if="catalogue.colours.length">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm font-semibold">{{ t('Colours of this item') }}</h3>
                    <p class="text-sm text-muted">{{ t('Each chosen colour becomes a variation that is sold and stocked on its own and shows in the store.') }}</p>
                </div>
                <Button size="sm" @click="chooseAll">{{ t('Choose all colours') }}</Button>
            </div>
            <div class="flex flex-wrap gap-2" role="group" :aria-label="t('Colours')">
                <button
                    v-for="colour in catalogue.colours"
                    :key="colour.id"
                    type="button"
                    :aria-pressed="isChosen(colour)"
                    class="inline-flex h-control-sm items-center gap-1.5 rounded-full border px-3 text-sm transition-colors"
                    :class="isChosen(colour) ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' : 'border-line text-ink hover:border-brand-300'"
                    @click="toggle(colour)"
                >
                    <Check v-if="isChosen(colour)" class="size-3.5" />
                    <span v-else-if="colour.swatch" class="inline-block size-3 rounded-full border border-line" :style="{ backgroundColor: colour.swatch }" />
                    {{ colour.name }}
                    <span v-if="!colour.is_active" class="text-xs text-muted">({{ t('Inactive') }})</span>
                </button>
            </div>
        </section>

        <div v-if="form.variations.length" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-muted">
                        <th class="px-2 py-1.5 text-start font-medium">{{ t('Colour') }}</th>
                        <th class="px-2 py-1.5 text-start font-medium">{{ t('Name') }}</th>
                        <th class="px-2 py-1.5 text-start font-medium">{{ t('Sku') }}</th>
                        <th class="px-2 py-1.5 text-start font-medium">{{ t('Barcode') }}</th>
                        <th v-if="firstList" class="px-2 py-1.5 text-end font-medium">{{ t('Kg price') }} ({{ firstList.name }})</th>
                        <th class="px-2 py-1.5 text-end font-medium">{{ t('On Hand') }}</th>
                        <th class="px-2 py-1.5 text-center font-medium">{{ t('Active') }}</th>
                        <th class="px-2 py-1.5"><span class="sr-only">{{ t('Delete') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in form.variations" :key="row.id ?? `new-${row.category_finish_id}`" class="border-t border-line align-top">
                        <td class="px-2 py-2 font-medium whitespace-nowrap">
                            {{ colourOf(row.category_finish_id)?.name ?? '—' }}
                            <p v-if="rowError(index, 'category_finish_id')" class="text-xs font-normal text-danger" role="alert">{{ rowError(index, 'category_finish_id') }}</p>
                        </td>
                        <td class="px-2 py-2 text-muted">{{ rowName(row) }}</td>
                        <td class="w-40 px-2 py-1"><TextInput v-model="row.sku" dir="ltr" :placeholder="t('Auto')" :error="rowError(index, 'sku')" /></td>
                        <td class="w-44 px-2 py-1"><TextInput v-model="row.barcode" dir="ltr" :error="rowError(index, 'barcode')" /></td>
                        <td v-if="firstList" class="px-2 py-2 text-end font-mono tabular-nums">{{ kgPrice(colourOf(row.category_finish_id)?.prices ?? [], firstList.id) ?? '—' }}</td>
                        <td class="px-2 py-2 text-end font-mono tabular-nums">{{ row.id ? (stock[String(row.id)]?.on_hand ?? '—') : '—' }}</td>
                        <td class="px-2 py-2 text-center"><Switch v-model="row.is_active" :aria-label="t('Active')" /></td>
                        <td class="px-2 py-1 text-end">
                            <Button variant="ghost" size="icon" :aria-label="t('Delete')" @click="removeRow(index)"><Trash2 class="size-4" /></Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="errors.variations" class="text-sm text-danger whitespace-pre-line" role="alert">{{ errors.variations }}</p>
    </div>
</template>

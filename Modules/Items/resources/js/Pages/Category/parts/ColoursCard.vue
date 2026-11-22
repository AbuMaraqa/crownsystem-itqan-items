<script setup lang="ts">
/**
 * «Colours and kg prices» of a weight category: its colours (price groups) and, for each price list,
 * the kg sell and buy price. Every item of the category in that colour is priced by this row, so the
 * kg price is never typed on an item. A colour with items is deactivated, not removed (the server
 * refuses). Saved with its own form (CategoryColourService).
 */
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import { controlClasses } from '@/Components/form/controlClasses';
import MultiSelect from '@/Components/form/MultiSelect.vue';
import type { Option } from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Alert from '@/Components/ui/Alert.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Switch from '@/Components/ui/Switch.vue';
import { t } from '@/lib/i18n';
import { Plus, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

export interface PriceListColumn {
    id: number;
    name: string;
}

/** A saved colour of the category (CategoryColours::forCategory). An empty PHP map arrives as a list. */
export interface ColourRow {
    id: number;
    finish_id: number;
    name: string;
    swatch: string | null;
    is_active: boolean;
    used: number;
    prices: Record<string, { sell: string | null; buy: string | null }> | never[];
}

interface FormRow {
    id: number | null;
    finish_id: number | null;
    /** Shown; sent only for a new colour typed by name. */
    name: string;
    swatch: string | null;
    is_active: boolean;
    used: number;
    prices: Record<string, { sell: string; buy: string }>;
}

const props = defineProps<{ categoryId: number; colours: ColourRow[]; priceLists: PriceListColumn[]; finishes: Option[] }>();

function toRows(colours: ColourRow[]): FormRow[] {
    return colours.map((colour) => ({
        id: colour.id,
        finish_id: colour.finish_id,
        name: colour.name,
        swatch: colour.swatch,
        is_active: colour.is_active,
        used: colour.used,
        prices: Object.fromEntries(
            props.priceLists.map((list) => {
                const saved = Array.isArray(colour.prices) ? undefined : colour.prices[String(list.id)];

                return [String(list.id), { sell: saved?.sell ?? '', buy: saved?.buy ?? '' }];
            }),
        ),
    }));
}

const form = useForm<{ rows: FormRow[] }>({ rows: toRows(props.colours) });
const errors = computed(() => form.errors as Record<string, string | undefined>);
const rowError = (index: number, field: string): string | undefined => errors.value[`rows.${index}.${field}`];

const emptyPrices = (): FormRow['prices'] => Object.fromEntries(props.priceLists.map((list) => [String(list.id), { sell: '', buy: '' }]));

/** A colour from the list joins once. */
const choosable = computed(() => props.finishes.filter((finish) => !form.rows.some((row) => String(row.finish_id) === String(finish.value))));
const chosenFinish = ref<string | number | null>(null);
const newName = ref('');

function addFinish(): void {
    const finish = props.finishes.find((option) => String(option.value) === String(chosenFinish.value));

    if (!finish) return;

    form.rows.push({ id: null, finish_id: Number(finish.value), name: finish.label, swatch: null, is_active: true, used: 0, prices: emptyPrices() });
    chosenFinish.value = null;
}

function addNew(): void {
    const name = newName.value.trim();

    if (name === '') return;

    form.rows.push({ id: null, finish_id: null, name, swatch: null, is_active: true, used: 0, prices: emptyPrices() });
    newName.value = '';
}

function removeRow(index: number): void {
    form.rows.splice(index, 1);
    form.clearErrors();
}

function submit(): void {
    form.transform((data) => ({
        rows: data.rows.map((row) => ({
            id: row.id,
            finish_id: row.finish_id,
            ...(row.finish_id === null ? { name: row.name } : {}),
            is_active: row.is_active,
            prices: row.prices,
        })),
    })).put(route('categories.colours.update', props.categoryId), {
        preserveScroll: true,
        onSuccess: () => {
            form.rows = toRows(props.colours);
            form.defaults();
        },
    });
}

const cell = [controlClasses, 'h-control-sm w-24 text-end font-mono tabular-nums'];
</script>

<template>
    <Card :title="t('Colours and kg prices')">
        <form class="grid gap-4" @submit.prevent="submit">
            <p class="text-sm text-muted">{{ t('Every item of this category in a colour is priced by its kg price here. A colour that has items is deactivated, not removed.') }}</p>

            <Alert v-if="!priceLists.length" tone="warning">{{ t('There are no active price lists yet.') }}</Alert>

            <div v-if="form.rows.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-muted">
                            <th rowspan="2" class="px-2 py-1.5 text-start align-bottom font-medium">{{ t('Colour') }}</th>
                            <th v-for="list in priceLists" :key="list.id" colspan="2" class="border-s border-line px-2 py-1.5 text-center font-medium">{{ list.name }}</th>
                            <th rowspan="2" class="px-2 py-1.5 text-center align-bottom font-medium">{{ t('Items') }}</th>
                            <th rowspan="2" class="px-2 py-1.5 text-center align-bottom font-medium">{{ t('Active') }}</th>
                            <th rowspan="2" class="px-2 py-1.5"><span class="sr-only">{{ t('Delete') }}</span></th>
                        </tr>
                        <tr class="text-xs text-muted">
                            <template v-for="list in priceLists" :key="list.id">
                                <th class="border-s border-line px-2 py-1 text-end font-normal">{{ t('Sell') }}</th>
                                <th class="px-2 py-1 text-end font-normal">{{ t('Buy') }}</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in form.rows" :key="row.id ?? `new-${row.finish_id ?? row.name}`" class="border-t border-line align-top">
                            <td class="px-2 py-2 font-medium whitespace-nowrap">
                                <span v-if="row.swatch" class="me-1.5 inline-block size-3 rounded-full border border-line align-middle" :style="{ backgroundColor: row.swatch }" />
                                {{ row.name }}
                                <span v-if="row.id === null" class="ms-1 text-xs font-normal text-brand-600">{{ t('New') }}</span>
                                <p v-if="rowError(index, 'name') || rowError(index, 'finish_id')" class="text-xs font-normal text-danger" role="alert">{{ rowError(index, 'name') ?? rowError(index, 'finish_id') }}</p>
                            </td>
                            <template v-for="list in priceLists" :key="list.id">
                                <td class="border-s border-line px-1 py-1">
                                    <input v-model="row.prices[String(list.id)].sell" type="number" step="any" min="0" dir="ltr" :aria-label="`${list.name} — ${t('Sell')}`" :aria-invalid="!!rowError(index, `prices.${list.id}.sell`)" :class="cell" />
                                    <p v-if="rowError(index, `prices.${list.id}.sell`)" class="text-xs text-danger" role="alert">{{ rowError(index, `prices.${list.id}.sell`) }}</p>
                                </td>
                                <td class="px-1 py-1">
                                    <input v-model="row.prices[String(list.id)].buy" type="number" step="any" min="0" dir="ltr" :aria-label="`${list.name} — ${t('Buy')}`" :aria-invalid="!!rowError(index, `prices.${list.id}.buy`)" :class="cell" />
                                    <p v-if="rowError(index, `prices.${list.id}.buy`)" class="text-xs text-danger" role="alert">{{ rowError(index, `prices.${list.id}.buy`) }}</p>
                                </td>
                            </template>
                            <td class="px-2 py-2 text-center font-mono tabular-nums">{{ row.used }}</td>
                            <td class="px-2 py-2 text-center"><Switch v-model="row.is_active" :aria-label="t('Active')" /></td>
                            <td class="px-1 py-1 text-end">
                                <Button variant="ghost" size="icon" :disabled="row.used > 0" :aria-label="row.used > 0 ? t('A colour that has items cannot be removed') : t('Delete')" @click="removeRow(index)">
                                    <Trash2 class="size-4" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-sm text-muted">{{ t('No colours yet') }}</p>

            <p v-if="errors.rows" class="text-sm text-danger" role="alert">{{ errors.rows }}</p>

            <div class="grid gap-3 border-t border-line pt-4 md:grid-cols-2">
                <div class="flex items-end gap-2">
                    <MultiSelect v-model="chosenFinish" single class="min-w-0 flex-1" :label="t('Add a colour from the list')" :options="choosable" />
                    <Button :disabled="chosenFinish === null" @click="addFinish"><Plus class="size-4" />{{ t('Add') }}</Button>
                </div>
                <div class="flex items-end gap-2">
                    <TextInput v-model="newName" class="min-w-0 flex-1" :label="t('Or a new colour')" :placeholder="t('e.g. RAL 7016')" @keydown.enter.prevent="addNew" />
                    <Button :disabled="newName.trim() === ''" @click="addNew"><Plus class="size-4" />{{ t('Add') }}</Button>
                </div>
            </div>

            <div class="flex justify-end">
                <Button type="submit" variant="primary" :loading="form.processing" :disabled="!form.isDirty">{{ t('Save colours and prices') }}</Button>
            </div>
        </form>
    </Card>
</template>

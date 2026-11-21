<script setup lang="ts">
import { computed, ref } from 'vue';

import { controlClasses } from '@/Components/form/controlClasses';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { Plus, Trash2 } from '@/lib/icons';

import type { StatusBadge } from '../../parts/statusBadge';
import type { Option } from '../../parts/types';
import PartyCell from '../parts/PartyCell.vue';
import FileField from '../parts/FileField.vue';
import LookupSelect from '../parts/LookupSelect.vue';
import { pickedItems, type PickedItem } from '../parts/orderItems';
import OrderItemsPicker from '../parts/OrderItemsPicker.vue';
import RowActions from '../parts/RowActions.vue';
import TabTable from '../parts/TabTable.vue';
import { useTabForm } from '../parts/useTabForm';
import type { FileRef, OrderItemChoice, TabOptions, TabRecord } from '../types';

/**
 * Customs reappraisals of the order: declared and reappraised values (the difference is shown,
 * red when it rises), the reappraisal elements one per row, and the affected items.
 */
const props = defineProps<{ orderId: number; currencyId: number | null; rows: TabRecord[]; options: TabOptions; orderItems: OrderItemChoice[] }>();

interface Line {
    reappraisal_element_id: number | null;
    amount: string | null;
    notes: string | null;
}

interface ReappraisalForm {
    reappraisal_date: string | null;
    reference_no: string | null;
    authority: string | null;
    status: number | null;
    declared_value: string | null;
    reappraised_value: string | null;
    currency_id: number | null;
    lines: Line[];
    items: PickedItem[];
    reason: string | null;
    notes: string | null;
    attachment: File | null;
    remove_attachment: boolean;
}

const today = (): string => new Date().toISOString().slice(0, 10);

const blank = (): ReappraisalForm => ({
    reappraisal_date: today(),
    reference_no: null,
    authority: null,
    status: props.options.reappraisalStatusOptions[0] ? Number(props.options.reappraisalStatusOptions[0].value) : null,
    declared_value: null,
    reappraised_value: null,
    currency_id: props.currencyId,
    lines: [],
    items: pickedItems(props.orderItems),
    reason: null,
    notes: null,
    attachment: null,
    remove_attachment: false,
});

const { open, editingId, form, add, edit, submit, remove } = useTabForm<ReappraisalForm>(() => props.orderId, 'reappraisals', blank);
const editingRow = ref<TabRecord | null>(null);

/** The active elements, plus the ones a saved reappraisal still uses (stopped or deleted since). */
const elementOptions = computed<Option[]>(() => {
    const saved = (editingRow.value?.element_options as Option[] | undefined) ?? [];

    return [...props.options.reappraisalElementOptions, ...saved.filter((option) => !props.options.reappraisalElementOptions.some((item) => String(item.value) === String(option.value)))];
});

/** One element per row: an element chosen in another row is not offered again. */
const optionsFor = (index: number): Option[] =>
    elementOptions.value.filter((option) => !form.lines.some((line, other) => other !== index && String(line.reappraisal_element_id) === String(option.value)));

const money = (value: number): string => value.toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const difference = computed(() => money(Number(form.reappraised_value || 0) - Number(form.declared_value || 0)));
const linesTotal = computed(() => money(form.lines.reduce((sum, line) => sum + Number(line.amount || 0), 0)));

function addRow(): void {
    editingRow.value = null;
    add();
}

function editRow(row: TabRecord): void {
    const values = row.form as Omit<ReappraisalForm, 'items'> & { items: { id: number; notes: string | null }[] };

    editingRow.value = row;
    edit(row.id, { ...values, items: pickedItems(props.orderItems, values.items) });
}

const lineError = (index: number, field: string): string | undefined => (form.errors as Record<string, string>)[`lines.${index}.${field}`];
</script>

<template>
    <TabTable
        :title="t('Customs Reappraisal')"
        :rows="rows"
        :columns="[
            { key: 'reappraisal_date', label: t('Reappraisal Date') },
            { key: 'reference_no', label: t('Reference No') },
            { key: 'authority', label: t('Appraising Authority') },
            { key: 'declared_value', label: t('Declared Value'), class: 'text-end' },
            { key: 'reappraised_value', label: t('Reappraised Value'), class: 'text-end' },
            { key: 'difference', label: t('Difference'), class: 'text-end' },
            { key: 'currency', label: t('Currency') },
            { key: 'lines', label: t('Reappraisal Elements') },
            { key: 'items', label: t('Affected Items') },
            { key: 'status', label: t('Status') },
            { key: 'created_by', label: t('Added By') },
        ]"
        :add-label="t('Add Reappraisal')"
        can-add
        @add="addRow"
    >
        <template #cell-reappraisal_date="{ row }"><span dir="ltr">{{ row.reappraisal_date }}</span></template>
        <template #cell-declared_value="{ row }"><span class="font-mono tabular-nums" dir="ltr">{{ row.declared_value }}</span></template>
        <template #cell-reappraised_value="{ row }"><span class="font-mono tabular-nums" dir="ltr">{{ row.reappraised_value }}</span></template>
        <template #cell-difference="{ row }">
            <span class="font-mono font-semibold tabular-nums" :class="{ 'text-danger': row.difference_tone === 'danger', 'text-success': row.difference_tone === 'success' }" dir="ltr">{{ row.difference }}</span>
        </template>
        <template #cell-lines="{ row }">
            <div v-if="(row.lines as string[]).length" class="flex flex-col items-start gap-1">
                <Badge v-for="(line, index) in row.lines as string[]" :key="index">{{ line }}</Badge>
                <span class="text-xs text-muted">{{ t('Total') }}: <span dir="ltr">{{ row.lines_total }}</span></span>
            </div>
            <span v-else class="text-muted">—</span>
        </template>
        <template #cell-items="{ row }">
            <div v-if="(row.items as string[]).length" class="flex flex-col items-start gap-1">
                <Badge v-for="(item, index) in row.items as string[]" :key="index">{{ item }}</Badge>
            </div>
            <span v-else class="text-muted">—</span>
        </template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="(row.status as StatusBadge).tone">{{ (row.status as StatusBadge).label }}</Badge>
        </template>
        <template #cell-created_by="{ row }"><PartyCell :user="row.created_by" /></template>
        <template #actions="{ row }"><RowActions @edit="editRow(row)" @delete="remove(row.id, t('Are you sure you want to delete this reappraisal?'))" /></template>
    </TabTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Reappraisal')" width="max-w-4xl">
        <form class="grid gap-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <TextInput v-model="form.reappraisal_date" type="date" :label="t('Reappraisal Date')" required :error="form.errors.reappraisal_date" />
                <TextInput v-model="form.reference_no" :label="t('Reference No')" :maxlength="255" dir="ltr" :error="form.errors.reference_no" />
                <TextInput v-model="form.authority" :label="t('Appraising Authority')" :maxlength="255" :error="form.errors.authority" />
                <SelectInput v-model="form.status" :label="t('Status')" required :options="options.reappraisalStatusOptions" :error="form.errors.status" />
                <TextInput v-model="form.declared_value" type="number" step="0.0001" min="0" dir="ltr" :label="t('Declared Value')" required :error="form.errors.declared_value" />
                <TextInput v-model="form.reappraised_value" type="number" step="0.0001" min="0" dir="ltr" :label="t('Reappraised Value')" required :error="form.errors.reappraised_value" />
                <SelectInput v-model="form.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :error="form.errors.currency_id" />
                <div>
                    <p class="mb-1.5 text-sm font-medium">{{ t('Difference') }}</p>
                    <p class="flex h-10 items-center rounded-lg bg-surface px-3 font-mono font-semibold tabular-nums" dir="ltr">{{ difference }}</p>
                </div>
            </div>

            <div class="grid gap-2">
                <p class="text-sm font-medium">{{ t('Reappraisal Elements') }}</p>
                <div v-for="(line, index) in form.lines" :key="index" class="grid items-start gap-2 rounded-lg border border-line p-2 sm:grid-cols-[2fr_1fr_2fr_auto]">
                    <LookupSelect v-model="line.reappraisal_element_id" type="reappraisal-element" :options="optionsFor(index)" :error="lineError(index, 'reappraisal_element_id')" />
                    <div>
                        <input v-model="line.amount" type="number" step="0.0001" min="0" dir="ltr" :placeholder="t('Value')" :aria-label="t('Value')" :class="[controlClasses, 'h-control text-end']" />
                        <p v-if="lineError(index, 'amount')" class="mt-1 text-sm text-danger">{{ lineError(index, 'amount') }}</p>
                    </div>
                    <input v-model="line.notes" :placeholder="t('Optional')" :aria-label="t('Notes')" :class="[controlClasses, 'h-control']" />
                    <button type="button" class="mt-2 rounded p-1 text-muted hover:text-danger" :aria-label="t('Remove')" @click="form.lines.splice(index, 1)"><Trash2 class="size-4" /></button>
                </div>
                <div class="flex items-center justify-between">
                    <Button size="sm" @click="form.lines.push({ reappraisal_element_id: null, amount: null, notes: null })"><Plus />{{ t('Add Element') }}</Button>
                    <span v-if="form.lines.length" class="text-sm">{{ t('Elements Total') }}: <span class="font-mono font-semibold" dir="ltr">{{ linesTotal }}</span></span>
                </div>
            </div>

            <OrderItemsPicker v-model="form.items" :label="t('Affected Items')" :choices="orderItems" />
            <TextareaInput v-model="form.reason" :label="t('Reappraisal Reason')" :rows="2" :error="form.errors.reason" />
            <FileField v-model="form.attachment" v-model:remove="form.remove_attachment" :label="t('Attachments')" :current="(editingRow?.attachment as FileRef | null) ?? null" :error="form.errors.attachment" />
            <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="2" :error="form.errors.notes" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>

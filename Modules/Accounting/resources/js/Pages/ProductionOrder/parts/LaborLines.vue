<script setup lang="ts">
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import { t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';
import { route } from '@/lib/route';

import type { Option } from '../../parts/types';
import { blankLabor, type LaborLine, type UnitOption } from '../types';
import LineCard from './LineCard.vue';

/**
 * The wages of a production order: quantity (hours, days, pieces) × the wage rate; a lump sum
 * counts once (ProductionCost on the server).
 */
const props = defineProps<{ totals: number[]; editable: boolean; errors: Record<string, string>; basisOptions: UnitOption[] }>();

const lines = defineModel<LaborLine[]>({ required: true });

const unit = (line: LaborLine): string => props.basisOptions.find((option) => option.value === line.rate_basis)?.unit ?? t('Hours');

function basisChanged(line: LaborLine, value: string | number | null | undefined): void {
    line.rate_basis = String(value ?? 'hour');

    if (line.rate_basis === 'fixed') line.quantity = '1';
}

function picked(line: LaborLine, option: Option): void {
    line.worker_label = option.label;
    line.worker_name = option.label;
}

const error = (index: number, field: string): string | undefined => props.errors[`labor.${index}.${field}`];
</script>

<template>
    <div class="grid gap-3">
        <p v-if="!lines.length" class="py-6 text-center text-sm text-muted">{{ t('No wages added yet.') }}</p>
        <LineCard
            v-for="(line, index) in lines"
            :key="index"
            :number="index + 1"
            :total="totals[index]"
            :title="[line.worker_name, line.description].filter(Boolean).join(' - ')"
            :editable="editable"
            @remove="lines.splice(index, 1)"
        >
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <RemoteSelect
                    v-if="editable"
                    v-model="line.worker_id"
                    :url="route('accounting.production-orders.workers')"
                    :initial="line.worker_id && line.worker_label ? [{ value: line.worker_id, label: line.worker_label }] : []"
                    :label="t('Worker')"
                    :placeholder="t('Select')"
                    :error="error(index, 'worker_id')"
                    @picked="(option: Option) => picked(line, option)"
                />
                <TextInput v-else :model-value="line.worker_label ?? '-'" :label="t('Worker')" disabled />
                <TextInput v-model="line.worker_name" :label="t('Worker Name')" :disabled="!editable" />
                <TextInput v-model="line.description" :label="t('Work Type')" :placeholder="t('e.g. Cutting, Assembly, Installation')" :disabled="!editable" />
                <SelectInput :model-value="line.rate_basis" :label="t('Wage Basis')" :options="basisOptions" :disabled="!editable" @update:model-value="(value) => basisChanged(line, value)" />
                <TextInput
                    v-model="line.quantity"
                    type="number"
                    step="0.0001"
                    min="0"
                    dir="ltr"
                    :label="`${t('Quantity')} (${unit(line)})`"
                    :disabled="!editable || line.rate_basis === 'fixed'"
                    :error="error(index, 'quantity')"
                />
                <TextInput
                    v-model="line.rate"
                    type="number"
                    step="0.0001"
                    min="0"
                    dir="ltr"
                    :label="line.rate_basis === 'fixed' ? t('Amount') : t('Wage Rate')"
                    :disabled="!editable"
                    :error="error(index, 'rate')"
                />
                <TextInput v-model="line.work_date" type="date" :label="t('Work Date')" :disabled="!editable" :error="error(index, 'work_date')" />
            </div>
        </LineCard>
        <div v-if="editable">
            <Button @click="lines.push(blankLabor())"><Plus />{{ t('Add Wage') }}</Button>
        </div>
    </div>
</template>

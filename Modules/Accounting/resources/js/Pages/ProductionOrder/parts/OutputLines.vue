<script setup lang="ts">
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import { t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';
import { route } from '@/lib/route';

import { getJson, withQuery } from '../../parts/http';
import type { Option } from '../../parts/types';
import { blankOutput, type OutputLine } from '../types';
import LineCard from './LineCard.vue';

/**
 * The items a production order produces (Itqan's «Production» tab). On posting each one enters the
 * order's warehouse with its share of the total cost: by weight when every produced item has a
 * weight, otherwise by quantity (ProductionCost::allocate on the server).
 */
const props = defineProps<{ totals: number[]; editable: boolean; errors: Record<string, string> }>();

const lines = defineModel<OutputLine[]>({ required: true });

async function picked(line: OutputLine, option: Option): Promise<void> {
    line.product_label = option.label;

    try {
        const defaults = await getJson<{ description: string | null; pricing_type: OutputLine['pricing_type'] } | null>(withQuery(route('accounting.production-orders.material'), { product_id: option.value }));

        if (defaults) {
            line.description = defaults.description;
            line.pricing_type = defaults.pricing_type;
        }
    } catch {
        // The line keeps what the user typed.
    }
}

const money = (value: number | undefined): string => (value ?? 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const unitCost = (line: OutputLine, index: number): string => {
    const quantity = Number(line.quantity) || 0;

    return quantity > 0 ? money((props.totals[index] ?? 0) / quantity) : '-';
};

const error = (index: number, field: string): string | undefined => props.errors[`outputs.${index}.${field}`];
</script>

<template>
    <div class="grid gap-3">
        <p v-if="!lines.length" class="py-6 text-center text-sm text-muted">{{ t('No produced items added yet.') }}</p>
        <LineCard v-for="(line, index) in lines" :key="index" :number="index + 1" :total="totals[index]" :title="line.description" :editable="editable" @remove="lines.splice(index, 1)">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    <RemoteSelect
                        v-if="editable"
                        v-model="line.product_id"
                        :url="route('accounting.production-orders.materials')"
                        :initial="line.product_id && line.product_label ? [{ value: line.product_id, label: line.product_label }] : []"
                        :label="t('Item')"
                        :placeholder="t('Select')"
                        required
                        :error="error(index, 'product_id')"
                        @picked="(option: Option) => picked(line, option)"
                    />
                    <TextInput v-else :model-value="line.product_label ?? '-'" :label="t('Item')" disabled />
                </div>
                <div class="lg:col-span-4">
                    <TextInput v-model="line.description" :label="t('Description')" :disabled="!editable" :error="error(index, 'description')" />
                </div>
                <div :class="line.pricing_type === 'weight' ? 'lg:col-span-1' : 'lg:col-span-2'">
                    <TextInput v-model="line.quantity" type="number" step="0.0001" min="0" dir="ltr" :label="t('Quantity')" :disabled="!editable" :error="error(index, 'quantity')" />
                </div>
                <div v-if="line.pricing_type === 'weight'" class="lg:col-span-1">
                    <TextInput v-model="line.weight" type="number" step="0.0001" min="0" dir="ltr" :label="`${t('Weight')} (${t('kg')})`" :disabled="!editable" :error="error(index, 'weight')" />
                </div>
                <div class="lg:col-span-2">
                    <TextInput :model-value="unitCost(line, index)" :label="t('Unit Cost')" dir="ltr" disabled />
                </div>
            </div>
        </LineCard>
        <p v-if="lines.length > 1" class="text-xs text-muted">{{ t('The total cost is shared between the produced items by weight when all of them have a weight, otherwise by quantity.') }}</p>
        <div v-if="editable">
            <Button @click="lines.push(blankOutput())"><Plus />{{ t('Add Produced Item') }}</Button>
        </div>
    </div>
</template>

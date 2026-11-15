<script setup lang="ts">
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import { t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';
import { route } from '@/lib/route';

import { getJson, withQuery } from '../../parts/http';
import type { Option } from '../../parts/types';
import { blankMaterial, type MaterialLine } from '../types';
import LineCard from './LineCard.vue';

/**
 * The items a production order consumes: a weight item costs its weight × the cost per kg, any
 * other its quantity × the unit cost (ProductionCost on the server). Choosing an item fills its
 * description, pricing type and card cost.
 */
const props = defineProps<{ totals: number[]; editable: boolean; errors: Record<string, string> }>();

const lines = defineModel<MaterialLine[]>({ required: true });

async function picked(line: MaterialLine, option: Option): Promise<void> {
    line.product_label = option.label;

    try {
        const defaults = await getJson<Partial<MaterialLine> | null>(withQuery(route('accounting.production-orders.material'), { product_id: option.value }));

        if (defaults) Object.assign(line, defaults);
    } catch {
        // The line keeps what the user typed.
    }
}

const error = (index: number, field: string): string | undefined => props.errors[`materials.${index}.${field}`];
</script>

<template>
    <div class="grid gap-3">
        <p v-if="!lines.length" class="py-6 text-center text-sm text-muted">{{ t('No items added yet.') }}</p>
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
                    <TextInput
                        v-model="line.unit_cost"
                        type="number"
                        step="0.0001"
                        min="0"
                        dir="ltr"
                        :label="line.pricing_type === 'weight' ? t('Cost per Kg') : t('Unit Cost')"
                        :disabled="!editable"
                        :error="error(index, 'unit_cost')"
                    />
                </div>
            </div>
        </LineCard>
        <div v-if="editable">
            <Button @click="lines.push(blankMaterial())"><Plus />{{ t('Add Item') }}</Button>
        </div>
    </div>
</template>

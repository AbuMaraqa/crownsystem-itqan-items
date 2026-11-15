<script setup lang="ts">
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import { t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';
import { route } from '@/lib/route';

import CreatableSelect from '../../parts/CreatableSelect.vue';
import { postJson } from '../../parts/http';
import type { Option } from '../../parts/types';
import { blankService, type MaterialLine, type ServiceLine, type UnitOption } from '../types';
import LineCard from './LineCard.vue';

/**
 * Services such as painting, done internally or by an external company: quantity × unit cost.
 * Choosing a service fills its description, cost, cost source and pricing basis; a service priced
 * by weight suggests the weight of the order's weight items. An external company records where the
 * pieces were sent and when they came back.
 */
const props = defineProps<{
    totals: number[];
    editable: boolean;
    errors: Record<string, string>;
    materials: MaterialLine[];
    materialsWeight: number;
    costSourceOptions: Option[];
    pricingBasisOptions: UnitOption[];
}>();

const lines = defineModel<ServiceLine[]>({ required: true });

const unit = (line: ServiceLine): string => props.pricingBasisOptions.find((option) => option.value === line.pricing_basis)?.unit ?? t('Unit');

async function defaults(productId: number | string): Promise<Partial<ServiceLine> | null> {
    try {
        return await postJson<Partial<ServiceLine> | null>(route('accounting.production-orders.service'), { product_id: productId, materials: props.materials });
    } catch {
        return null;
    }
}

async function picked(line: ServiceLine, option: Option): Promise<void> {
    line.product_label = option.label;

    const values = await defaults(option.value);

    if (values) Object.assign(line, values);
    if (line.cost_source !== 'external') line.service_provider_id = null;
}

function sourceChanged(line: ServiceLine, value: string | number | null | undefined): void {
    line.cost_source = String(value ?? 'internal');

    if (line.cost_source !== 'external') line.service_provider_id = null;
}

function basisChanged(line: ServiceLine, value: string | number | null | undefined): void {
    line.pricing_basis = String(value ?? 'quantity');

    // A service priced by weight takes the weight of the order's weight items (summed by the server's cost preview).
    if (line.pricing_basis === 'weight' && props.materialsWeight > 0) line.quantity = props.materialsWeight.toFixed(4);
}

const error = (index: number, field: string): string | undefined => props.errors[`services.${index}.${field}`];
</script>

<template>
    <div class="grid gap-3">
        <p class="text-sm text-muted">{{ t('Services such as painting, done internally or by an external company. For an external company, record where the pieces were sent and when they came back.') }}</p>
        <p v-if="!lines.length" class="py-6 text-center text-sm text-muted">{{ t('No services added yet.') }}</p>
        <LineCard v-for="(line, index) in lines" :key="index" :number="index + 1" :total="totals[index]" :title="line.description" :editable="editable" @remove="lines.splice(index, 1)">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-12">
                <div class="lg:col-span-3">
                    <RemoteSelect
                        v-if="editable"
                        v-model="line.product_id"
                        :url="route('accounting.production-orders.services')"
                        :initial="line.product_id && line.product_label ? [{ value: line.product_id, label: line.product_label }] : []"
                        :label="t('Service')"
                        :placeholder="t('Select')"
                        :error="error(index, 'product_id')"
                        @picked="(option: Option) => picked(line, option)"
                    />
                    <TextInput v-else :model-value="line.product_label ?? '-'" :label="t('Service')" disabled />
                </div>
                <div class="lg:col-span-3">
                    <TextInput v-model="line.description" :label="t('Description')" :disabled="!editable" :error="error(index, 'description')" />
                </div>
                <div class="lg:col-span-2">
                    <SelectInput :model-value="line.cost_source" :label="t('Cost Source')" :options="costSourceOptions" :disabled="!editable" @update:model-value="(value) => sourceChanged(line, value)" />
                </div>
                <div class="lg:col-span-2">
                    <SelectInput :model-value="line.pricing_basis" :label="t('Pricing Basis')" :options="pricingBasisOptions" :disabled="!editable" @update:model-value="(value) => basisChanged(line, value)" />
                </div>
                <div class="lg:col-span-1">
                    <TextInput v-model="line.quantity" type="number" step="0.0001" min="0" dir="ltr" :label="`${t('Quantity')} (${unit(line)})`" :disabled="!editable" :error="error(index, 'quantity')" />
                </div>
                <div class="lg:col-span-1">
                    <TextInput v-model="line.unit_cost" type="number" step="0.0001" min="0" dir="ltr" :label="t('Unit Cost')" :disabled="!editable" :error="error(index, 'unit_cost')" />
                </div>
                <template v-if="line.cost_source === 'external'">
                    <div class="lg:col-span-3">
                        <CreatableSelect
                            v-if="editable"
                            v-model="line.service_provider_id"
                            :url="route('accounting.production-orders.providers')"
                            :initial="line.service_provider_id && line.provider_label ? [{ value: line.service_provider_id, label: line.provider_label }] : []"
                            :label="t('Service Provider')"
                            :hint="t('Leave empty if the provider is not registered.')"
                            :error="error(index, 'service_provider_id')"
                            :create-url="route('accounting.production-orders.providers.store')"
                            :create-title="t('Add Supplier')"
                            @picked="(option: Option) => (line.provider_label = option.label)"
                        />
                        <TextInput v-else :model-value="line.provider_label ?? '-'" :label="t('Service Provider')" disabled />
                    </div>
                    <div class="lg:col-span-3">
                        <TextInput v-model="line.provider_reference" :label="t('Provider Reference')" :placeholder="t('Provider invoice or delivery note no.')" :disabled="!editable" />
                    </div>
                    <div class="lg:col-span-2">
                        <TextInput v-model="line.sent_date" type="date" :label="t('Sent Date')" :disabled="!editable" :error="error(index, 'sent_date')" />
                    </div>
                    <div class="lg:col-span-2">
                        <TextInput v-model="line.received_date" type="date" :label="t('Received Date')" :disabled="!editable" :error="error(index, 'received_date')" />
                    </div>
                </template>
                <div :class="line.cost_source === 'external' ? 'lg:col-span-2' : 'lg:col-span-12'">
                    <TextInput v-model="line.notes" :label="t('Notes')" :disabled="!editable" />
                </div>
            </div>
        </LineCard>
        <div v-if="editable">
            <Button @click="lines.push(blankService())"><Plus />{{ t('Add Service') }}</Button>
        </div>
    </div>
</template>

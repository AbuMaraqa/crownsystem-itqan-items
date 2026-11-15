<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { Plus, Trash2 } from '@/lib/icons';

import { createGridApi, FormErrors } from './api';
import { computedCell, iconButton } from './classes';
import type { GridContext, ServiceLine, ServiceOptions } from './types';
import { extraLineAmounts, fixed4, money, num } from './useItemsGrid';

/**
 * The service lines of a sales order or invoice, beside the items grid: a service is priced by
 * quantity, per kg or per trip, and has a cost (internal, or an external provider — registered or
 * not) that its invoice posts. Choosing a service brings its party price and its card's cost.
 * A per-kg service suggests the document's weight (`documentWeight`: the customer's materials,
 * else the items). Pass the same lines to the grid's `context.extraLines` so they count in the
 * totals. The document's service (SalesServiceLine) checks and computes everything again.
 *
 *   <ServiceLines v-model:lines="form.services" :document="'sales-order'" :editable :context="gridContext" :options="serviceOptions" :document-weight="weight" />
 */
const props = defineProps<{
    document: string;
    editable: boolean;
    context: GridContext;
    options: ServiceOptions;
    /** kg suggested for a per-kg service. */
    documentWeight?: number;
    error?: string;
}>();

const lines = defineModel<ServiceLine[]>('lines', { required: true });

const api = createGridApi(props.document, () => props.context);
const WEIGHT = 'weight';
const EXTERNAL = 'external';

const uid = (): string => 'service-' + Math.random().toString(36).slice(2, 11);

function blankLine(): ServiceLine {
    return {
        _key: uid(),
        id: null,
        product_id: null,
        product_label: '',
        description: null,
        pricing_type: 'simple',
        pricing_basis: 'quantity',
        quantity: '1',
        unit_price: '0.0000',
        discount_mode: 'rate',
        discount_rate: '0',
        discount_amount: '0.0000',
        line_total: '0.0000',
        cost_source: 'internal',
        service_provider_id: null,
        service_provider_label: null,
        unit_cost: '0.0000',
        notes: null,
    };
}

const unitOf = (basis: string): string => props.options.pricing_bases.find((option) => option.value === basis)?.unit ?? t('Unit');
const suggestedWeight = (): string | null => ((props.documentWeight ?? 0) > 0 ? fixed4(props.documentWeight ?? 0) : null);

function add(): void {
    lines.value.push(blankLine());
}

function remove(index: number): void {
    lines.value.splice(index, 1);
}

/** A chosen service: its description, pricing basis, party price, and its card's cost and source. */
async function chooseService(line: ServiceLine, option: { value: string | number; label: string }): Promise<void> {
    line.product_label = option.label;

    try {
        const payload = await api.service(Number(option.value));

        if (!payload) return;

        line.description = payload.description;
        line.pricing_type = 'simple';
        line.pricing_basis = payload.pricing_basis;
        line.unit_price = payload.unit_price;
        line.unit_cost = payload.unit_cost;
        line.cost_source = payload.cost_source;
        line.service_provider_id = null;
        line.service_provider_label = null;
        line.discount_mode = 'rate';
        line.discount_rate = '0';
        line.discount_amount = '0.0000';
        line.quantity = (payload.pricing_basis === WEIGHT ? suggestedWeight() : null) ?? '1';
    } catch {
        // The line keeps what it had; saving checks the service again.
    }
}

/** Per kg: the document's weight is the suggested quantity. */
function changeBasis(line: ServiceLine, basis: string | number | null): void {
    line.pricing_basis = String(basis ?? 'quantity');

    if (line.pricing_basis === WEIGHT && suggestedWeight()) line.quantity = suggestedWeight();
}

function changeCostSource(line: ServiceLine, source: string | number | null): void {
    line.cost_source = String(source ?? 'internal');

    if (line.cost_source !== EXTERNAL) {
        line.service_provider_id = null;
        line.service_provider_label = null;
    }
}

function costSummary(line: ServiceLine): { cost: number; margin: number } {
    const quantity = num(line.quantity);
    const cost = quantity * num(line.unit_cost);

    return { cost, margin: quantity * num(line.unit_price) - cost };
}

// Line total and the derived discount field, as the server computes them (display only).
watch(
    lines,
    (value) => {
        for (const line of value) {
            const amounts = extraLineAmounts(line);

            if (line.discount_mode === 'amount') {
                if (num(line.discount_rate) !== amounts.rate) line.discount_rate = fixed4(amounts.rate);
            } else if (num(line.discount_amount) !== amounts.discount) {
                line.discount_amount = fixed4(amounts.discount);
            }

            if (num(line.line_total) !== amounts.total || line.line_total === '') line.line_total = fixed4(amounts.total);
        }
    },
    { deep: true, immediate: true },
);

const count = computed(() => lines.value.filter((line) => line.product_id).length);

/* ---------- a new external provider (QuickCreate) ---------- */

const providerFor = ref<ServiceLine | null>(null);
const providerOpen = computed({
    get: () => providerFor.value !== null,
    set: (open: boolean) => {
        if (!open) providerFor.value = null;
    },
});
const provider = reactive({ name: '', email: '', phone: '' });
const providerErrors = ref<Record<string, string>>({});
const providerBusy = ref(false);

function openProvider(line: ServiceLine): void {
    Object.assign(provider, { name: '', email: '', phone: '' });
    providerErrors.value = {};
    providerFor.value = line;
}

async function saveProvider(): Promise<void> {
    providerBusy.value = true;

    try {
        const created = await api.createServiceProvider({ name: provider.name, email: provider.email || null, phone: provider.phone || null });

        if (providerFor.value) {
            providerFor.value.service_provider_id = created.value;
            providerFor.value.service_provider_label = created.label;
        }

        providerFor.value = null;
    } catch (error) {
        providerErrors.value = error instanceof FormErrors ? error.errors : { name: t('Something went wrong. Please try again.') };
    } finally {
        providerBusy.value = false;
    }
}
</script>

<template>
    <div class="space-y-3">
        <p v-if="lines.length === 0" class="rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-muted">{{ t('No services added yet.') }}</p>

        <div
            v-for="(line, index) in lines"
            :key="line._key"
            class="rounded-xl border border-line bg-surface/40 p-4 dark:bg-white/[0.02]"
            :data-service-line="index"
        >
            <div class="mb-3 flex items-center justify-between gap-2">
                <span class="text-xs font-semibold text-muted">{{ t('Service') }} {{ index + 1 }}</span>
                <button
                    v-if="editable"
                    type="button"
                    :class="[iconButton, 'hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10']"
                    :aria-label="t('Remove')"
                    :title="t('Remove')"
                    @click="remove(index)"
                >
                    <Trash2 />
                </button>
            </div>

            <div class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-4">
                    <RemoteSelect
                        v-if="editable"
                        v-model="line.product_id"
                        :url="api.servicesUrl()"
                        :initial="line.product_id ? [{ value: line.product_id, label: line.product_label }] : []"
                        :label="t('Service')"
                        :placeholder="t('Select')"
                        required
                        @picked="chooseService(line, $event)"
                    />
                    <TextInput v-else :model-value="line.product_label" :label="t('Service')" disabled />
                </div>
                <div class="md:col-span-3">
                    <TextInput v-model="line.description" :label="t('Description')" :disabled="!editable" />
                </div>
                <div class="md:col-span-2">
                    <SelectInput
                        :model-value="line.pricing_basis"
                        :label="t('Pricing Basis')"
                        :options="options.pricing_bases"
                        :disabled="!editable"
                        @update:model-value="changeBasis(line, $event ?? null)"
                    />
                </div>
                <div class="md:col-span-1">
                    <TextInput v-model="line.quantity" :label="`${t('Quantity')} (${unitOf(line.pricing_basis)})`" dir="ltr" required :disabled="!editable" />
                </div>
                <div class="md:col-span-2">
                    <TextInput v-model="line.unit_price" :label="t('Price')" dir="ltr" :disabled="!editable" />
                </div>
            </div>

            <div class="mt-3 grid gap-3 md:grid-cols-12">
                <div class="md:col-span-1">
                    <TextInput v-model="line.discount_rate" :label="`${t('Discount')} %`" dir="ltr" :disabled="!editable" @update:model-value="line.discount_mode = 'rate'" />
                </div>
                <div class="md:col-span-2">
                    <TextInput v-model="line.discount_amount" :label="t('Discount Amount')" dir="ltr" :disabled="!editable" @update:model-value="line.discount_mode = 'amount'" />
                </div>
                <div class="md:col-span-2">
                    <span class="mb-1.5 block text-sm font-medium text-gray-800 dark:text-gray-200">{{ t('Total') }}</span>
                    <div :class="[computedCell, 'h-10 font-semibold text-gray-900 dark:text-white']" dir="ltr">{{ money(line.line_total) }}</div>
                </div>
                <div class="md:col-span-2">
                    <SelectInput
                        :model-value="line.cost_source"
                        :label="t('Cost Source')"
                        :options="options.cost_sources"
                        :disabled="!editable"
                        @update:model-value="changeCostSource(line, $event ?? null)"
                    />
                </div>
                <div v-if="line.cost_source === EXTERNAL" class="md:col-span-2">
                    <template v-if="editable">
                        <RemoteSelect
                            :key="`${line._key}-${line.service_provider_id ?? ''}`"
                            v-model="line.service_provider_id"
                            :url="api.serviceProvidersUrl()"
                            :initial="line.service_provider_id ? [{ value: line.service_provider_id, label: line.service_provider_label ?? '' }] : []"
                            :label="t('Service Provider')"
                            :placeholder="t('Select')"
                            :hint="t('Leave empty if the provider is not registered; the cost is recorded as an accrued expense.')"
                            @picked="line.service_provider_label = $event.label"
                        />
                        <button type="button" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400" @click="openProvider(line)">
                            <Plus class="size-3.5" />{{ t('New Provider') }}
                        </button>
                    </template>
                    <TextInput v-else :model-value="line.service_provider_label ?? '-'" :label="t('Service Provider')" disabled />
                </div>
                <div class="md:col-span-1">
                    <TextInput v-model="line.unit_cost" :label="t('Unit Cost')" dir="ltr" :disabled="!editable" />
                </div>
                <div class="md:col-span-2">
                    <span class="mb-1.5 block text-sm font-medium text-gray-800 dark:text-gray-200">{{ t('Cost / Margin') }}</span>
                    <div :class="[computedCell, 'h-10 gap-1']" dir="ltr">
                        <span>{{ money(costSummary(line).cost, 2) }}</span>
                        <span class="text-muted">/</span>
                        <span class="font-semibold" :class="costSummary(line).margin < 0 ? 'text-error-600 dark:text-error-400' : 'text-success-600 dark:text-success-400'">{{ money(costSummary(line).margin, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <button
                v-if="editable"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-200 px-3 py-1.5 text-sm font-medium text-brand-600 transition-colors hover:bg-brand-50 dark:border-brand-500/30 dark:text-brand-400 dark:hover:bg-brand-500/10"
                @click="add"
            >
                <Plus />{{ t('Add Service') }}
            </button>
            <span v-if="count" class="text-xs text-muted">{{ count }} {{ t('Services') }}</span>
        </div>

        <Modal v-model:open="providerOpen" :title="t('New Provider')">
            <form class="grid gap-4" @submit.prevent="saveProvider">
                <TextInput v-model="provider.name" :label="t('Name')" required :error="providerErrors.name" />
                <TextInput v-model="provider.email" type="email" :label="t('Email')" dir="ltr" :error="providerErrors.email" />
                <TextInput v-model="provider.phone" :label="t('Phone')" dir="ltr" :error="providerErrors.phone" />
            </form>
            <template #footer>
                <Button @click="providerOpen = false">{{ t('Cancel') }}</Button>
                <Button variant="primary" :loading="providerBusy" @click="saveProvider">{{ t('Save') }}</Button>
            </template>
        </Modal>
    </div>
</template>

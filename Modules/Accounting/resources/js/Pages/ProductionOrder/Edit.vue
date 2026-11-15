<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import FormActions from '@/Components/form/FormActions.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { CircleCheck, Info, Play, Returns, Trash2, X } from '@/lib/icons';
import { route } from '@/lib/route';

import ProductionOrderFields from './ProductionOrderFields.vue';
import type { ProductionContext, ProductionOptions, ProductionOrderForm, ProductionRecord } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<ProductionOptions & { order: ProductionOrderForm; context: ProductionContext; record: ProductionRecord }>();

const form = useForm<ProductionOrderForm>(props.order);

// After a save the server sends the lines again: the form starts from them.
watch(
    () => props.order,
    (order) => {
        form.defaults(order);
        form.reset();
    },
);

const id = computed(() => props.record.id);
const editable = computed(() => props.record.editable);

/** Start and complete save the open changes first, as the old page did. */
function stage(name: 'start' | 'complete'): void {
    const url = route(`accounting.production-orders.${name}`, id.value);

    if (editable.value) form.post(url, { preserveScroll: true });
    else router.post(url, {}, { preserveScroll: true });
}

async function complete(): Promise<void> {
    if (await confirm({ title: t('Complete production and lock its cost?'), confirmLabel: t('Complete Production'), tone: 'primary' })) stage('complete');
}

function reopen(): void {
    router.post(route('accounting.production-orders.reopen', id.value), {}, { preserveScroll: true });
}

async function cancel(): Promise<void> {
    if (await confirm({ title: t('Cancel this production order?'), confirmLabel: t('Cancel') })) {
        router.post(route('accounting.production-orders.cancel', id.value), {}, { preserveScroll: true });
    }
}

async function remove(): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: props.record.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.production-orders.destroy', id.value));
    }
}
</script>

<template>
    <PageHeader :title="`${t('Production Order')} ${record.reference_no}`" :parents="[{ title: t('Production Orders'), href: route('accounting.production-orders.index') }]">
        <template #actions>
            <Badge v-if="record.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge>
            <Button v-if="record.can_start && can('Production Order Start')" variant="light" :loading="form.processing" @click="stage('start')"><Play />{{ t('Start Production') }}</Button>
            <Button v-if="record.can_complete && can('Production Order Complete')" variant="primary" :loading="form.processing" @click="complete"><CircleCheck />{{ t('Complete Production') }}</Button>
            <Button v-if="record.can_reopen && can('Production Order Complete')" @click="reopen"><Returns />{{ t('Reopen') }}</Button>
            <Button v-if="record.can_cancel && can('Production Order Cancel')" variant="danger" @click="cancel"><X />{{ t('Cancel') }}</Button>
            <Button v-if="record.can_delete && can('Production Order Delete')" variant="ghost" :aria-label="t('Delete')" @click="remove"><Trash2 /></Button>
        </template>
    </PageHeader>

    <div v-if="record.sales_order" class="mb-4 flex items-center gap-2 rounded-xl border border-blue-light-200 bg-blue-light-50 px-4 py-3 text-sm font-medium text-blue-light-700 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-400">
        <Info class="size-4 shrink-0" />
        <span>{{ t('This production order fulfils sales order') }}</span>
        <a v-if="can('Sales Order Update')" class="font-semibold underline" dir="ltr" :href="route('accounting.sales-orders.edit', record.sales_order.id)">{{ record.sales_order.reference_no }}</a>
        <span v-else class="font-semibold" dir="ltr">{{ record.sales_order.reference_no }}</span>
    </div>

    <form @submit.prevent="form.put(route('accounting.production-orders.update', id), { preserveScroll: true })">
        <ProductionOrderFields :form="form" :options="props" :context="context" :reference-no="record.reference_no" :status-label="record.status?.label ?? ''" :readonly="!editable" />
        <FormActions v-if="editable && can('Production Order Update')" :processing="form.processing" :cancel-href="route('accounting.production-orders.index')" :submit-label="t('Update')" />
    </form>
</template>

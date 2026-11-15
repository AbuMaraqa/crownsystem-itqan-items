<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { CircleAlert } from '@/lib/icons';
import { route } from '@/lib/route';

import type { Option } from '../parts/types';
import PurchaseOrderFields from './PurchaseOrderFields.vue';
import type { PurchaseOrderForm, PurchaseOrderOptions } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<
    PurchaseOrderOptions & {
        order: Omit<PurchaseOrderForm, 'attachments' | 'removed_attachments' | 'attachment_order'>;
        supplier: Option | null;
        customer: Option | null;
        referenceNo: string;
        statusLabel: string;
        branchClosed: boolean;
    }
>();

const form = useForm<PurchaseOrderForm>({ ...props.order, attachments: [], removed_attachments: [], attachment_order: [] });
</script>

<template>
    <PageHeader :title="t('Add Purchase Order')" :description="t('Fill in the order info, then add its items.')" :parents="[{ title: t('Purchase Orders'), href: route('accounting.purchase-orders.index') }]">
        <template #actions>
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-1.5 text-sm">
                <span class="text-muted">{{ t('Reference No') }}</span>
                <bdi class="font-semibold">{{ referenceNo }}</bdi>
            </span>
            <span class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">{{ statusLabel }}</span>
        </template>
    </PageHeader>

    <div v-if="branchClosed" class="mb-4 flex items-center gap-2 rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm font-medium text-warning-800 dark:border-warning-500/25 dark:bg-warning-500/10 dark:text-warning-300">
        <CircleAlert class="size-5 shrink-0" />{{ t('Your branch is currently closed.') }}
    </div>

    <form @submit.prevent="form.post(route('accounting.purchase-orders.store'))">
        <PurchaseOrderFields :form="form" :options="props" :supplier="supplier" :customer="customer" />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.purchase-orders.index')" :submit-label="t('Save')" />
    </form>
</template>

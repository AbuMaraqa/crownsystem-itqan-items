<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import DocumentActions from '../parts/DocumentActions.vue';
import { useDocumentActions } from '../parts/useDocumentActions';
import StockTransferFields from './StockTransferFields.vue';
import type { TransferForm, TransferPageProps } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<TransferPageProps>();

const form = useForm<TransferForm>(props.transfer);

const actions = useDocumentActions('transfers', form, () => null, {
    postQuestion: t('Post this transfer?'),
    postLabel: t('Post Transfer'),
    cancelQuestion: '',
    cancelLabel: '',
});
</script>

<template>
    <PageHeader :title="t('New Stock Transfer')" :parents="[{ title: t('Stock Transfers'), href: route('accounting.inventory.transfers.index') }]">
        <template #actions>
            <DocumentActions
                prefix="transfers"
                entity="Stock Transfer"
                :record="null"
                savable
                :processing="form.processing"
                :post-label="t('Post Transfer')"
                :cancel-label="t('Cancel Transfer')"
                @save="actions.save"
                @post="actions.post"
            />
        </template>
    </PageHeader>

    <StockTransferFields :form="form" :from-context="fromContext" :to-context="toContext" :warehouse-options="warehouseOptions" :can-see-cost="canSeeCost" :readonly="false" />
</template>

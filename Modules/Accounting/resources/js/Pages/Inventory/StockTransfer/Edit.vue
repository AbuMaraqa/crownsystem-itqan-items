<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import DocumentActions from '../parts/DocumentActions.vue';
import DocumentMeta from '../parts/DocumentMeta.vue';
import type { DocumentRecord } from '../parts/types';
import { useDocumentActions } from '../parts/useDocumentActions';
import StockTransferFields from './StockTransferFields.vue';
import type { TransferForm, TransferPageProps } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<TransferPageProps & { record: DocumentRecord & { from_warehouse: string | null; to_warehouse: string | null } }>();

const form = useForm<TransferForm>(props.transfer);

watch(
    () => props.transfer,
    (transfer) => {
        form.defaults(transfer);
        form.reset();
    },
);

const editable = computed(() => props.record.editable);

const actions = useDocumentActions('transfers', form, () => props.record.id, {
    postQuestion: t('Post this transfer?'),
    postLabel: t('Post Transfer'),
    cancelQuestion: t('Cancel this transfer? The stock will be returned.'),
    cancelLabel: t('Cancel Transfer'),
});
</script>

<template>
    <PageHeader :title="`${t('Stock Transfer')} ${record.reference_no}`" :parents="[{ title: t('Stock Transfers'), href: route('accounting.inventory.transfers.index') }]">
        <template #actions>
            <DocumentActions
                prefix="transfers"
                entity="Stock Transfer"
                :record="record"
                :savable="editable"
                :processing="form.processing"
                :post-label="t('Post Transfer')"
                :cancel-label="t('Cancel Transfer')"
                @save="actions.save"
                @post="actions.post"
                @cancel="actions.cancel"
                @remove="actions.remove"
            />
        </template>
    </PageHeader>

    <DocumentMeta :record="record" :extra="[record.from_warehouse ? `${t('From Warehouse')}: ${record.from_warehouse}` : null, record.to_warehouse ? `${t('To Warehouse')}: ${record.to_warehouse}` : null]" class="mb-4" />

    <StockTransferFields
        :form="form"
        :from-context="fromContext"
        :to-context="toContext"
        :warehouse-options="warehouseOptions"
        :can-see-cost="canSeeCost"
        :readonly="!editable"
        :posted="record.is_posted"
    />
</template>

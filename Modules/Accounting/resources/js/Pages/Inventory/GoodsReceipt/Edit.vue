<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import DocumentActions from '../parts/DocumentActions.vue';
import DocumentMeta from '../parts/DocumentMeta.vue';
import { useDocumentActions } from '../parts/useDocumentActions';
import type { DocumentRecord } from '../parts/types';
import GoodsReceiptFields from './GoodsReceiptFields.vue';
import type { ReceiptForm, ReceiptPageProps } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<ReceiptPageProps & { record: DocumentRecord }>();

const form = useForm<ReceiptForm>(props.receipt);

// After a save the server sends the lines again with their ids: the form starts from them.
watch(
    () => props.receipt,
    (receipt) => {
        form.defaults(receipt);
        form.reset();
    },
);

const editable = computed(() => props.record.editable);

const actions = useDocumentActions('goods-receipts', form, () => props.record.id, {
    postQuestion: t('Post this receipt? The quantities will be added to the warehouse stock.'),
    postLabel: t('Post & Receive'),
    cancelQuestion: t('Cancel this receipt? The stock and the journal entry will be reversed.'),
    cancelLabel: t('Cancel Receipt'),
});
</script>

<template>
    <PageHeader :title="`${t('Goods Receipt')} ${record.reference_no}`" :parents="[{ title: t('Goods Receipts'), href: route('accounting.inventory.goods-receipts.index') }]">
        <template #actions>
            <DocumentActions
                prefix="goods-receipts"
                entity="Goods Receipt"
                :record="record"
                :savable="editable && !!order"
                :processing="form.processing"
                :post-label="t('Post & Receive')"
                :cancel-label="t('Cancel Receipt')"
                @save="actions.save"
                @post="actions.post"
                @cancel="actions.cancel"
                @remove="actions.remove"
            />
        </template>
    </PageHeader>

    <DocumentMeta :record="record" :party="order?.party ?? null" :order="order?.reference_no ?? null" class="mb-4" />

    <GoodsReceiptFields
        :form="form"
        :order="order"
        :context="context"
        :warehouse-options="warehouseOptions"
        :can-see-cost="canSeeCost"
        :value="editable ? value : record.total_cost"
        :readonly="!editable"
        :record-id="record.id"
    />
</template>

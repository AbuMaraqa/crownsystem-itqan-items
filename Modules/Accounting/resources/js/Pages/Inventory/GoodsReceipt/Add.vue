<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import DocumentActions from '../parts/DocumentActions.vue';
import { useDocumentActions } from '../parts/useDocumentActions';
import GoodsReceiptFields from './GoodsReceiptFields.vue';
import type { ReceiptForm, ReceiptPageProps } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<ReceiptPageProps>();

const form = useForm<ReceiptForm>(props.receipt);

const actions = useDocumentActions('goods-receipts', form, () => null, {
    postQuestion: t('Post this receipt? The quantities will be added to the warehouse stock.'),
    postLabel: t('Post & Receive'),
    cancelQuestion: '',
    cancelLabel: '',
});
</script>

<template>
    <PageHeader :title="t('New Goods Receipt')" :parents="[{ title: t('Goods Receipts'), href: route('accounting.inventory.goods-receipts.index') }]">
        <template #actions>
            <DocumentActions
                prefix="goods-receipts"
                entity="Goods Receipt"
                :record="null"
                :savable="!!order"
                :processing="form.processing"
                :post-label="t('Post & Receive')"
                :cancel-label="t('Cancel Receipt')"
                @save="actions.save"
                @post="actions.post"
            />
        </template>
    </PageHeader>

    <GoodsReceiptFields :form="form" :order="order" :context="context" :warehouse-options="warehouseOptions" :can-see-cost="canSeeCost" :value="value" :readonly="false" />
</template>

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
import StockAdjustmentFields from './StockAdjustmentFields.vue';
import type { AdjustmentForm, AdjustmentPageProps } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<AdjustmentPageProps & { record: DocumentRecord & { warehouse: string | null; type_label: string } }>();

const form = useForm<AdjustmentForm>(props.adjustment);

// After a save the server sends the lines again: the form starts from them.
watch(
    () => props.adjustment,
    (adjustment) => {
        form.defaults(adjustment);
        form.reset();
    },
);

const editable = computed(() => props.record.editable);

const actions = useDocumentActions('adjustments', form, () => props.record.id, {
    postQuestion: t('Post this document? The stock will be updated.'),
    postLabel: t('Post'),
    cancelQuestion: t('Cancel this document? The stock will be reversed.'),
    cancelLabel: t('Cancel'),
});
</script>

<template>
    <PageHeader :title="`${record.type_label} ${record.reference_no}`" :parents="[{ title: t('Stock Adjustments'), href: route('accounting.inventory.adjustments.index') }]">
        <template #actions>
            <DocumentActions
                prefix="adjustments"
                entity="Stock Adjustment"
                :record="record"
                :savable="editable"
                :processing="form.processing"
                :post-label="t('Post')"
                :cancel-label="t('Cancel')"
                :print-label="form.type === 'count' && record.is_draft ? t('Print Count Sheet') : t('Print')"
                @save="actions.save"
                @post="actions.post"
                @cancel="actions.cancel"
                @remove="actions.remove"
            />
        </template>
    </PageHeader>

    <DocumentMeta :record="record" :extra="[record.warehouse]" class="mb-4" />

    <StockAdjustmentFields
        :form="form"
        :context="context"
        :warehouse-options="warehouseOptions"
        :type-options="typeOptions"
        :can-see-cost="canSeeCost"
        :readonly="!editable"
        saved
    />
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import DocumentActions from '../parts/DocumentActions.vue';
import { useDocumentActions } from '../parts/useDocumentActions';
import StockAdjustmentFields from './StockAdjustmentFields.vue';
import type { AdjustmentForm, AdjustmentPageProps } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<AdjustmentPageProps>();

const form = useForm<AdjustmentForm>(props.adjustment);

const typeLabel = computed(() => props.typeOptions.find((option) => option.value === form.type)?.label ?? '');

const actions = useDocumentActions('adjustments', form, () => null, {
    postQuestion: t('Post this document? The stock will be updated.'),
    postLabel: t('Post'),
    cancelQuestion: '',
    cancelLabel: '',
});
</script>

<template>
    <PageHeader :title="`${t('New')} — ${typeLabel}`" :parents="[{ title: t('Stock Adjustments'), href: route('accounting.inventory.adjustments.index') }]">
        <template #actions>
            <DocumentActions
                prefix="adjustments"
                entity="Stock Adjustment"
                :record="null"
                savable
                :processing="form.processing"
                :post-label="t('Post')"
                :cancel-label="t('Cancel')"
                @save="actions.save"
                @post="actions.post"
            />
        </template>
    </PageHeader>

    <StockAdjustmentFields :form="form" :context="context" :warehouse-options="warehouseOptions" :type-options="typeOptions" :can-see-cost="canSeeCost" :readonly="false" />
</template>

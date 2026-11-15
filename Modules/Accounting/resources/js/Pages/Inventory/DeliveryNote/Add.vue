<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import DocumentActions from '../parts/DocumentActions.vue';
import { useDocumentActions } from '../parts/useDocumentActions';
import DeliveryNoteFields from './DeliveryNoteFields.vue';
import type { DeliveryForm, DeliveryPageProps } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<DeliveryPageProps>();

const form = useForm<DeliveryForm>(props.note);

const actions = useDocumentActions('delivery-notes', form, () => null, {
    postQuestion: t('Post this delivery? The quantities will be deducted from the warehouse stock.'),
    postLabel: t('Post & Deliver'),
    cancelQuestion: '',
    cancelLabel: '',
});
</script>

<template>
    <PageHeader :title="t('New Delivery Note')" :parents="[{ title: t('Delivery Notes'), href: route('accounting.inventory.delivery-notes.index') }]">
        <template #actions>
            <DocumentActions
                prefix="delivery-notes"
                entity="Delivery Note"
                :record="null"
                :savable="!!order"
                :processing="form.processing"
                :post-label="t('Post & Deliver')"
                :cancel-label="t('Cancel Delivery')"
                @save="actions.save"
                @post="actions.post"
            />
        </template>
    </PageHeader>

    <DeliveryNoteFields :form="form" :order="order" :context="context" :warehouse-options="warehouseOptions" :can-see-cost="canSeeCost" :readonly="false" />
</template>

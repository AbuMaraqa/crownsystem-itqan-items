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
import DeliveryNoteFields from './DeliveryNoteFields.vue';
import type { DeliveryForm, DeliveryPageProps } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<DeliveryPageProps & { record: DocumentRecord }>();

const form = useForm<DeliveryForm>(props.note);

// After a save the server sends the lines again with their ids: the form starts from them.
watch(
    () => props.note,
    (note) => {
        form.defaults(note);
        form.reset();
    },
);

const editable = computed(() => props.record.editable);

const actions = useDocumentActions('delivery-notes', form, () => props.record.id, {
    postQuestion: t('Post this delivery? The quantities will be deducted from the warehouse stock.'),
    postLabel: t('Post & Deliver'),
    cancelQuestion: t('Cancel this delivery? The items will return to stock and the journal entry will be reversed.'),
    cancelLabel: t('Cancel Delivery'),
});
</script>

<template>
    <PageHeader :title="`${t('Delivery Note')} ${record.reference_no}`" :parents="[{ title: t('Delivery Notes'), href: route('accounting.inventory.delivery-notes.index') }]">
        <template #actions>
            <DocumentActions
                prefix="delivery-notes"
                entity="Delivery Note"
                :record="record"
                :savable="editable && !!order"
                :processing="form.processing"
                :post-label="t('Post & Deliver')"
                :cancel-label="t('Cancel Delivery')"
                :print-label="record.is_draft ? t('Print Pick List') : t('Print')"
                @save="actions.save"
                @post="actions.post"
                @cancel="actions.cancel"
                @remove="actions.remove"
            />
        </template>
    </PageHeader>

    <DocumentMeta :record="record" :party="order?.party ?? null" :order="order?.reference_no ?? null" class="mb-4" />

    <DeliveryNoteFields :form="form" :order="order" :context="context" :warehouse-options="warehouseOptions" :can-see-cost="canSeeCost" :readonly="!editable" :record="record" />
</template>

<script setup lang="ts">
import { computed } from 'vue';

import UserCell from '@/Components/table/UserCell.vue';
import Button from '@/Components/ui/Button.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import DocumentIndex from '../parts/DocumentIndex.vue';
import type { DocumentRow, DocumentTableOptions, UserCellData } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface DeliveryRow extends DocumentRow {
    sales_order: string | null;
    party: UserCellData | null;
    warehouse: string | null;
    driver_name: string | null;
}

const props = defineProps<DocumentTableOptions & { table: TablePayload<DeliveryRow> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'sales_order', label: t('Sales Order') },
    { key: 'party', label: t('Customer') },
    { key: 'warehouse', label: t('Warehouse') },
    { key: 'driver_name', label: t('Driver Name'), toggleable: true },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'sales_order', label: t('Sales Order') },
    { type: 'text', key: 'party', label: t('Customer') },
    { type: 'text', key: 'driver_name', label: t('Driver Name') },
]);
</script>

<template>
    <DocumentIndex
        :title="t('Delivery Notes')"
        prefix="delivery-notes"
        entity="Delivery Note"
        date-column="delivery_date"
        :date-label="t('Delivery Date')"
        :table="table"
        :options="props"
        :columns="columns"
        :filters="filters"
    >
        <template #actions>
            <Button v-if="can('Delivery Note Create')" variant="primary" :href="route('accounting.inventory.delivery-notes.add')"><Plus />{{ t('Add') }}</Button>
        </template>
        <template #cell-sales_order="{ row }"><bdi dir="ltr" class="font-mono">{{ row.sales_order ?? '-' }}</bdi></template>
        <template #cell-party="{ row }">
            <UserCell v-if="row.party" :name="row.party.name" :avatar="row.party.avatar" :detail="row.party.detail" />
            <span v-else class="text-muted">-</span>
        </template>
    </DocumentIndex>
</template>

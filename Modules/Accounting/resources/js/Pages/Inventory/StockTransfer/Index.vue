<script setup lang="ts">
import { computed } from 'vue';

import Button from '@/Components/ui/Button.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import DocumentIndex from '../parts/DocumentIndex.vue';
import type { DocumentRow, DocumentTableOptions } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface TransferRow extends DocumentRow {
    from_warehouse: string | null;
    to_warehouse: string | null;
}

const props = defineProps<DocumentTableOptions & { table: TablePayload<TransferRow> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'from_warehouse', label: t('From Warehouse') },
    { key: 'to_warehouse', label: t('To Warehouse') },
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'from_warehouse', label: t('From Warehouse'), options: props.warehouseOptions },
    { key: 'to_warehouse', label: t('To Warehouse'), options: props.warehouseOptions },
]);
</script>

<template>
    <DocumentIndex
        :title="t('Stock Transfers')"
        prefix="transfers"
        entity="Stock Transfer"
        date-column="transfer_date"
        :date-label="t('Transfer Date')"
        :table="table"
        :options="props"
        :columns="columns"
        :filters="filters"
    >
        <template #actions>
            <Button v-if="can('Stock Transfer Create')" variant="primary" :href="route('accounting.inventory.transfers.add')"><Plus />{{ t('Add') }}</Button>
        </template>
    </DocumentIndex>
</template>

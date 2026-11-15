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

interface ReceiptRow extends DocumentRow {
    purchase_order: string | null;
    party: UserCellData | null;
    warehouse: string | null;
    container_no: string | null;
}

const props = defineProps<DocumentTableOptions & { table: TablePayload<ReceiptRow> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'purchase_order', label: t('Purchase Order') },
    { key: 'party', label: t('Supplier') },
    { key: 'warehouse', label: t('Warehouse') },
    { key: 'container_no', label: t('Container No'), toggleable: true },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'purchase_order', label: t('Purchase Order') },
    { type: 'text', key: 'party', label: t('Supplier') },
    { type: 'text', key: 'container_no', label: t('Container No') },
]);
</script>

<template>
    <DocumentIndex
        :title="t('Goods Receipts')"
        prefix="goods-receipts"
        entity="Goods Receipt"
        date-column="receipt_date"
        :date-label="t('Receipt Date')"
        :table="table"
        :options="props"
        :columns="columns"
        :filters="filters"
    >
        <template #actions>
            <Button v-if="can('Goods Receipt Create')" variant="primary" :href="route('accounting.inventory.goods-receipts.add')"><Plus />{{ t('Add') }}</Button>
        </template>
        <template #cell-purchase_order="{ row }"><bdi dir="ltr" class="font-mono">{{ row.purchase_order ?? '-' }}</bdi></template>
        <template #cell-party="{ row }">
            <UserCell v-if="row.party" :name="row.party.name" :avatar="row.party.avatar" :detail="row.party.detail" />
            <span v-else class="text-muted">-</span>
        </template>
    </DocumentIndex>
</template>

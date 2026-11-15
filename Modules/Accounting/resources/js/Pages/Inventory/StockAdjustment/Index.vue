<script setup lang="ts">
import { computed } from 'vue';

import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import DocumentIndex from '../parts/DocumentIndex.vue';
import type { DocumentRow, DocumentTableOptions, Option, StatusBadge } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface AdjustmentRow extends DocumentRow {
    type: StatusBadge | null;
    warehouse: string | null;
    reason: string | null;
}

const props = defineProps<DocumentTableOptions & { table: TablePayload<AdjustmentRow>; typeOptions: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'type', label: t('Type'), sort: 'type' },
    { key: 'warehouse', label: t('Warehouse') },
    { key: 'reason', label: t('Reason') },
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'type', label: t('Type'), options: props.typeOptions },
    { type: 'text', key: 'reason', label: t('Reason') },
]);
</script>

<template>
    <DocumentIndex
        :title="t('Stock Adjustments')"
        prefix="adjustments"
        entity="Stock Adjustment"
        date-column="adjustment_date"
        :table="table"
        :options="props"
        :columns="columns"
        :filters="filters"
    >
        <template #actions>
            <template v-if="can('Stock Adjustment Create')">
                <Button
                    v-for="type in typeOptions"
                    :key="type.value"
                    :variant="type.value === 'count' ? 'primary' : 'secondary'"
                    :href="route('accounting.inventory.adjustments.add', { type: type.value })"
                >
                    <Plus />{{ t('New') }} — {{ type.label }}
                </Button>
            </template>
        </template>
        <template #cell-type="{ row }">
            <Badge v-if="row.type" :tone="row.type.tone">{{ row.type.label }}</Badge>
        </template>
        <template #cell-reason="{ row }"><span class="line-clamp-1 max-w-64">{{ row.reason ?? '-' }}</span></template>
    </DocumentIndex>
</template>

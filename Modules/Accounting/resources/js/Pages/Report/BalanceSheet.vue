<script setup lang="ts">
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { ArrowDown } from '@/lib/icons';
import { route } from '@/lib/route';

import ReportSectionCard from './parts/ReportSectionCard.vue';
import type { ReportSection } from './parts/types';
import { useReportFilters } from './parts/useReportFilters';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    filters: { to: string | null };
    sections: ReportSection[];
    totals: { assets: string; liabilities_and_equity: string; difference: string };
    balanced: boolean;
}>();

const { filters, loading, query } = useReportFilters({ ...props.filters });

const exportUrl = computed(() => route('accounting.reports.balance-sheet.export', query()));
// Balance sheet accounts are cumulative: their ledger runs up to the report date.
const ledgerQuery = computed(() => ({ to: props.filters.to }));
</script>

<template>
    <PageHeader :title="t('Balance Sheet')">
        <template #actions>
            <Button :href="exportUrl" external><ArrowDown />{{ t('Export to Excel') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <Card>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TextInput v-model="filters.to" type="date" :label="t('To')" />
            </div>
        </Card>

        <div class="grid gap-4 transition-opacity lg:grid-cols-3" :class="{ 'opacity-60': loading }">
            <ReportSectionCard v-for="section in sections" :key="section.title" :section="section" :ledger-query="ledgerQuery" />
        </div>

        <Card>
            <div class="grid gap-4 text-sm md:grid-cols-3">
                <div>{{ t('Total Assets') }}: <strong class="tabular-nums" dir="ltr">{{ totals.assets }}</strong></div>
                <div>{{ t('Total Liabilities and Equity') }}: <strong class="tabular-nums" dir="ltr">{{ totals.liabilities_and_equity }}</strong></div>
                <div>
                    <span v-if="!balanced" class="font-semibold text-danger">{{ t('Difference') }}: <span class="tabular-nums" dir="ltr">{{ totals.difference }}</span></span>
                    <span v-else class="font-semibold text-success-600 dark:text-success-400">{{ t('The balance sheet is balanced.') }}</span>
                </div>
            </div>
        </Card>
    </div>
</template>

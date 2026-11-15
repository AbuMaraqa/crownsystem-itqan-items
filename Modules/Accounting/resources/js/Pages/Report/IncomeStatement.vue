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
    filters: { from: string | null; to: string | null };
    sections: ReportSection[];
    net: { label: string; amount: string; profit: boolean };
}>();

const { filters, loading, query } = useReportFilters({ ...props.filters });

const exportUrl = computed(() => route('accounting.reports.income-statement.export', query()));
const ledgerQuery = computed(() => ({ from: props.filters.from, to: props.filters.to }));
</script>

<template>
    <PageHeader :title="t('Income Statement')">
        <template #actions>
            <Button :href="exportUrl" external><ArrowDown />{{ t('Export to Excel') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <Card>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TextInput v-model="filters.from" type="date" :label="t('From')" />
                <TextInput v-model="filters.to" type="date" :label="t('To')" />
            </div>
        </Card>

        <div class="grid gap-4 transition-opacity lg:grid-cols-2" :class="{ 'opacity-60': loading }">
            <ReportSectionCard v-for="section in sections" :key="section.title" :section="section" :ledger-query="ledgerQuery" />
        </div>

        <Card>
            <p class="text-base">
                {{ net.label }}:
                <strong class="tabular-nums" :class="net.profit ? 'text-success-600 dark:text-success-400' : 'text-danger'" dir="ltr">{{ net.amount }}</strong>
            </p>
        </Card>
    </div>
</template>

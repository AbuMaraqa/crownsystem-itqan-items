<script setup lang="ts">
import { computed } from 'vue';

import { t } from '@/lib/i18n';

import type { DocumentRecord } from './types';

/** One line under the title of a saved document: party, order, date, who posted it and who made it. */
const props = defineProps<{
    record: DocumentRecord;
    party?: string | null;
    order?: string | null;
    extra?: (string | null | undefined)[];
}>();

const items = computed(() =>
    [
        props.party,
        props.order,
        ...(props.extra ?? []),
        props.record.date,
        props.record.posted_by ? `${t('Posted by')} ${props.record.posted_by}${props.record.posted_at ? ` · ${props.record.posted_at}` : ''}` : null,
        props.record.created_by ? `${t('Created By')}: ${props.record.created_by}` : null,
    ].filter((item): item is string => !!item),
);
</script>

<template>
    <div v-if="items.length" class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border border-line bg-card px-4 py-2.5 text-sm text-muted shadow-card">
        <span v-for="(item, index) in items" :key="index" class="flex items-center gap-4">
            <span v-if="index > 0" class="text-gray-300 dark:text-gray-600">•</span>
            <bdi>{{ item }}</bdi>
        </span>
    </div>
</template>

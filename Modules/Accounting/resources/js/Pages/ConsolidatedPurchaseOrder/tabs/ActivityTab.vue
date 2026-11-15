<script setup lang="ts">
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

/** The order's activity log (`RecordInfo::of()`): who created it, and every change with its values. */
interface Activity {
    id: number;
    description: string;
    causer: string | null;
    old: Record<string, unknown> | null;
    new: Record<string, unknown> | null;
    created_at: string | null;
    created_ago: string | null;
}

defineProps<{ activity: { created_by: string | null; created_at: string | null; updated_at: string | null; activities: Activity[] } }>();

const pretty = (value: unknown): string => JSON.stringify(value, null, 2);
</script>

<template>
    <Card :title="t('Activity Log')">
        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-muted">{{ t('Created By') }}</dt>
                <dd class="font-medium">{{ activity.created_by ?? t('Unknown') }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ t('Created At') }}</dt>
                <dd class="tabular-nums" dir="ltr">{{ activity.created_at }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ t('Updated At') }}</dt>
                <dd class="tabular-nums" dir="ltr">{{ activity.updated_at }}</dd>
            </div>
        </dl>
        <p v-if="!activity.activities.length" class="py-6 text-center text-sm text-muted">{{ t('No activities recorded') }}</p>
        <ul v-else class="mt-4 space-y-2">
            <li v-for="item in activity.activities" :key="item.id" class="rounded-lg border border-line p-3 text-sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium">{{ item.description }}</p>
                        <p v-if="item.causer" class="mt-0.5 text-muted">{{ t('By') }}: {{ item.causer }}</p>
                    </div>
                    <time class="shrink-0 text-xs text-muted" :title="item.created_at ?? undefined">{{ item.created_ago }}</time>
                </div>
                <details v-if="item.old || item.new" class="mt-2">
                    <summary class="cursor-pointer text-brand-600 dark:text-brand-400">{{ t('Show Details') }}</summary>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2" dir="ltr">
                        <pre v-if="item.old" class="max-h-32 overflow-auto rounded bg-surface p-2 text-xs">{{ pretty(item.old) }}</pre>
                        <pre v-if="item.new" class="max-h-32 overflow-auto rounded bg-surface p-2 text-xs">{{ pretty(item.new) }}</pre>
                    </div>
                </details>
            </li>
        </ul>
    </Card>
</template>

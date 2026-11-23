<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import ColorInput from '@/Components/form/ColorInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';
import { toneText } from '@/lib/tones';

import type { SettingsForm } from './types';

const props = defineProps<{ form: InertiaForm<SettingsForm> }>();

const valid = (hex: string) => /^#[0-9a-f]{6}$/i.test(hex);

/** The variables the saved theme sets (DashboardTheme), set here first so the preview follows the picker before saving. */
const preview = computed(() => ({
    ...(valid(props.form.stock_min_1_color) ? { '--color-stock-min-1': props.form.stock_min_1_color } : {}),
    ...(valid(props.form.stock_min_2_color) ? { '--color-stock-min-2': props.form.stock_min_2_color } : {}),
}));

const limits = computed(() => [
    { key: 'stock_min_1_color' as const, label: t('Min Limit 1 color'), hint: t('Stock at or below Min Limit 1.'), tone: 'stock-min-1' as const, status: t('Low'), quantity: '25' },
    { key: 'stock_min_2_color' as const, label: t('Min Limit 2 color'), hint: t('Stock at or below Min Limit 2.'), tone: 'stock-min-2' as const, status: t('Critical'), quantity: '8' },
]);
</script>

<template>
    <Card :title="t('Stock limit colors')" :description="t('An item whose stock falls to a minimum limit or below is shown in that limit\'s color.')">
        <div class="grid gap-6 md:grid-cols-2" :style="preview">
            <div v-for="limit in limits" :key="limit.key" class="space-y-3">
                <ColorInput v-model="form[limit.key]" :label="limit.label" :hint="limit.hint" :error="form.errors[limit.key]" required />
                <div class="flex items-center gap-3 rounded-lg border border-line bg-surface px-3 py-2 text-sm">
                    <span class="text-muted">{{ t('Preview') }}:</span>
                    <b class="tabular-nums" :class="toneText[limit.tone]" dir="ltr">{{ limit.quantity }}</b>
                    <Badge :tone="limit.tone" dot>{{ limit.status }}</Badge>
                </div>
            </div>
        </div>
    </Card>
</template>

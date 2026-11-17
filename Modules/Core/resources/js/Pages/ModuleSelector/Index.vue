<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

defineOptions({ layout: AdminLayout });

interface ModulePackage {
    key: string;
    name: string;
    description: string;
    modules: string[];
    features: string[];
}

defineProps<{ packages: ModulePackage[]; current: string | null }>();

const busy = ref<string | null>(null);

async function activate(item: ModulePackage): Promise<void> {
    const ok = await confirm({
        title: t('Activate this package?'),
        description: t('Every module outside the package will be disabled for the whole system.'),
        confirmLabel: t('Activate'),
        tone: 'primary',
    });

    if (!ok) {
        return;
    }

    busy.value = item.key;
    router.post(route('module-selector.select'), { package: item.key }, { onFinish: () => (busy.value = null) });
}
</script>

<template>
    <PageHeader :title="t('Module packages')" :description="t('Choose the package of modules this system runs.')" />

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
        <Card v-for="item in packages" :key="item.key" :title="item.name" :description="item.description">
            <template v-if="current === item.key" #actions>
                <Badge tone="success">{{ t('Active') }}</Badge>
            </template>

            <div class="space-y-4">
                <div class="flex flex-wrap gap-1.5">
                    <Badge v-for="module in item.modules" :key="module" tone="neutral">{{ module }}</Badge>
                </div>
                <ul v-if="item.features.length" class="space-y-1.5 text-sm text-muted">
                    <li v-for="feature in item.features" :key="feature" class="flex items-start gap-2">
                        <span class="mt-2 size-1.5 shrink-0 rounded-full bg-brand-500" />
                        <span>{{ feature }}</span>
                    </li>
                </ul>
            </div>

            <template #footer>
                <Button variant="primary" class="w-full" :loading="busy === item.key" @click="activate(item)">{{ t('Activate') }}</Button>
            </template>
        </Card>
    </div>
</template>

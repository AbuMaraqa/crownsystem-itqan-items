<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';

import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { ChevronUp, Plus, Search } from '@/lib/icons';
import { route } from '@/lib/route';

import CategoryTreeNode from './parts/CategoryTreeNode.vue';
import type { CategoryNode } from './parts/types';
import { createCategoryTree, provideCategoryTree } from './parts/useCategoryTree';

defineOptions({ layout: AdminLayout });

/**
 * The categories tree: the roots load with the page and a category's children and items when it
 * opens; a search walks the whole tree and keeps the ancestors of each match. Drag a category to
 * reorder it or to put it under another category.
 */
const props = defineProps<{
    tree: CategoryNode[];
    filters: { search: string; show_inactive: boolean };
    searching: boolean;
    pageSize: number;
}>();

const filters = reactive({ ...props.filters });
const loading = ref(false);
const state = createCategoryTree(props.pageSize, () => filters.show_inactive, () => props.searching);

provideCategoryTree(state);

function reload(): void {
    router.get(
        route('categories.tree'),
        { search: filters.search || undefined, show_inactive: filters.show_inactive ? undefined : 0 },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['tree', 'filters', 'searching'],
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
            onSuccess: () => {
                state.branches.clear();
                state.collapseAll();
            },
        },
    );
}

let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => filters.search,
    () => {
        clearTimeout(timer);
        timer = setTimeout(reload, 300);
    },
);
watch(() => filters.show_inactive, reload);
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <PageHeader :title="t('Categories Tree')" :description="t('Drag a category to reorder it or to move it under another category.')" :parents="[{ title: t('Categories List'), href: route('categories.index') }]">
        <template #actions>
            <Button @click="state.collapseAll"><ChevronUp class="size-4" />{{ t('Collapse All') }}</Button>
            <Button v-if="can('Category Create')" variant="primary" :href="route('categories.add')"><Plus class="size-4" />{{ t('Add Category') }}</Button>
        </template>
    </PageHeader>

    <div class="overflow-hidden rounded-xl border border-line bg-card shadow-card">
        <div class="flex flex-col gap-3 border-b border-line p-4 md:flex-row md:items-center">
            <label class="relative w-full md:max-w-sm">
                <span class="sr-only">{{ t('Search categories...') }}</span>
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                <input v-model="filters.search" type="search" :placeholder="t('Search categories...')" class="h-control w-full rounded-control border border-line bg-card ps-9 pe-3 text-sm outline-none focus:border-brand-300 focus:shadow-focus-ring" />
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-muted">
                <input v-model="filters.show_inactive" type="checkbox" class="size-4 rounded accent-brand-500" />
                {{ t('Show inactive') }}
            </label>
        </div>

        <div class="p-2 transition-opacity" :class="{ 'opacity-50': loading }" role="tree" :aria-label="t('Categories Tree')" :aria-busy="loading">
            <CategoryTreeNode v-for="(node, index) in tree" :key="node.id" :node="node" :level="0" :parent-id="null" :index="index" />
            <p v-if="!tree.length" class="px-3 py-8 text-center text-sm text-muted">{{ t('No data available') }}</p>

            <!-- Drop here to take a category out of its parent and make it a main category. -->
            <div
                v-if="can('Category Update')"
                class="mt-1 rounded-lg border-2 border-dashed px-3 py-2 text-center text-xs transition-colors"
                :class="state.over.value === 'root' ? 'border-brand-400 bg-brand-50 text-brand-600 dark:bg-brand-500/10' : 'border-transparent text-gray-400'"
                @dragover.prevent="state.dragId.value !== null && (state.over.value = 'root')"
                @dragleave="state.over.value = null"
                @drop.prevent="state.drop(null, 9999)"
            >
                {{ t('Drop here to make it a main category') }}
            </div>
        </div>
    </div>
</template>

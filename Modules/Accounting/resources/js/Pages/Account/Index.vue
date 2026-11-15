<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { ChevronDown, ChevronUp, Returns, Search } from '@/lib/icons';
import { route } from '@/lib/route';

import AccountTreeNode from './parts/AccountTreeNode.vue';
import type { AccountNode } from './parts/types';

defineOptions({ layout: AdminLayout });

interface Filters {
    search: string;
    type: number | null;
    postable: boolean;
    active: boolean;
}

const props = defineProps<{
    tree: AccountNode[];
    visibleCount: number;
    totalCount: number;
    /** A filter narrows the tree: every branch is shown open. */
    expandAll: boolean;
    filters: Filters;
    types: Option[];
}>();

const filters = reactive<Filters>({ ...props.filters });
const expanded = ref(new Set<number>());
const loading = ref(false);

function reload(): void {
    router.get(
        route('accounting.accounts.index'),
        {
            filter: {
                search: filters.search || undefined,
                type: filters.type ?? undefined,
                postable: filters.postable ? 1 : undefined,
                active: filters.active ? 1 : 0,
            },
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['tree', 'visibleCount', 'totalCount', 'expandAll', 'filters'],
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
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
watch(() => [filters.type, filters.postable, filters.active], reload);

function toggle(id: number): void {
    const next = new Set(expanded.value);

    if (next.has(id)) next.delete(id);
    else next.add(id);

    expanded.value = next;
}

function branchIds(nodes: AccountNode[], ids: number[] = []): number[] {
    for (const node of nodes) {
        if (node.children.length) {
            ids.push(node.id);
            branchIds(node.children, ids);
        }
    }

    return ids;
}

const expandEverything = (): void => {
    expanded.value = new Set(branchIds(props.tree));
};
const collapseEverything = (): void => {
    expanded.value = new Set();
};

function resetFilters(): void {
    Object.assign(filters, { search: '', type: null, postable: false, active: true });
}

async function remove(node: AccountNode): Promise<void> {
    if (await confirm({ title: t('Are you sure you want to delete this account?'), description: `${node.code} - ${node.name}` })) {
        router.delete(route('accounting.accounts.destroy', node.id), {
            preserveScroll: true,
            onSuccess: () => {
                const next = new Set(expanded.value);
                next.delete(node.id);
                expanded.value = next;
            },
        });
    }
}

const control = 'h-10 w-full rounded-lg border border-line bg-card px-3 text-sm outline-none focus:border-brand-300 focus:shadow-focus-ring';
const columns = computed(() => [t('Account'), t('Type'), t('Normal Balance'), t('Balance'), t('Status')]);
</script>

<template>
    <PageHeader :title="t('Chart of Accounts')" :description="`${visibleCount} / ${totalCount}`">
        <template #actions>
            <Button v-if="can('Account Create')" variant="primary" :href="route('accounting.accounts.add')">{{ t('Add Account') }}</Button>
        </template>
    </PageHeader>

    <div class="overflow-hidden rounded-xl border border-line bg-card shadow-card">
        <div class="grid gap-3 border-b border-line p-4 md:grid-cols-12 md:items-center">
            <label class="relative md:col-span-4">
                <span class="sr-only">{{ t('Search accounts') }}</span>
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                <input v-model="filters.search" type="search" :placeholder="t('Search accounts')" :class="[control, 'ps-9']" />
            </label>

            <select v-model="filters.type" :class="[control, 'md:col-span-3']" :aria-label="t('Type')">
                <option :value="null">{{ t('All Types') }}</option>
                <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
            </select>

            <div class="flex flex-wrap items-center gap-4 text-sm md:col-span-3">
                <label class="inline-flex items-center gap-2">
                    <input v-model="filters.postable" type="checkbox" class="size-4 rounded accent-brand-500" />
                    <span>{{ t('Postable Only') }}</span>
                </label>
                <label class="inline-flex items-center gap-2">
                    <input v-model="filters.active" type="checkbox" class="size-4 rounded accent-brand-500" />
                    <span>{{ t('Active Only') }}</span>
                </label>
            </div>

            <div class="flex items-center gap-2 md:col-span-2 md:justify-end">
                <Button size="icon-md" :aria-label="t('Expand All')" :disabled="expandAll" @click="expandEverything"><ChevronDown /></Button>
                <Button size="icon-md" :aria-label="t('Collapse All')" :disabled="expandAll" @click="collapseEverything"><ChevronUp /></Button>
                <Button size="icon-md" :aria-label="t('Reset')" @click="resetFilters"><Returns /></Button>
            </div>
        </div>

        <div class="overflow-x-auto transition-opacity" :class="{ 'opacity-50': loading }" :aria-busy="loading">
            <div class="lg:min-w-[70rem]">
                <div class="hidden gap-4 border-b border-line bg-gray-50/80 px-4 py-3 text-[13px] font-medium text-gray-500 lg:grid lg:grid-cols-[minmax(20rem,1.8fr)_7rem_13rem_minmax(10rem,0.9fr)_7rem_3.5rem] dark:bg-white/[0.02] dark:text-gray-400">
                    <div v-for="(column, index) in columns" :key="column" :class="{ 'text-end': index === 3 }">{{ column }}</div>
                    <div><span class="sr-only">{{ t('Actions') }}</span></div>
                </div>

                <div class="divide-y divide-line">
                    <AccountTreeNode v-for="node in tree" :key="node.id" :node="node" :expand-all="expandAll" :expanded="expanded" @toggle="toggle" @delete="remove" />
                </div>

                <p v-if="!tree.length" class="p-8 text-center text-sm text-muted">{{ t('No accounts found') }}</p>
            </div>
        </div>
    </div>
</template>

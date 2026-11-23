<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { Check, ChevronDown, ChevronUp, Plus, Returns, Search } from '@/lib/icons';
import { route } from '@/lib/route';

import AccountClassBand from './parts/AccountClassBand.vue';
import AccountTreeNode from './parts/AccountTreeNode.vue';
import type { AccountNode, AccountSummary } from './parts/types';

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
    summary: AccountSummary;
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
            only: ['tree', 'visibleCount', 'totalCount', 'expandAll', 'summary', 'filters'],
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

const filtered = computed(() => filters.search !== '' || filters.type !== null || filters.postable || !filters.active);

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

const description = computed(() =>
    props.visibleCount === props.totalCount
        ? t(':count accounts', { count: props.totalCount })
        : t('Showing :visible of :total accounts', { visible: props.visibleCount, total: props.totalCount }),
);

const chips = computed(() => [
    { key: 'postable' as const, label: t('Postable Only') },
    { key: 'active' as const, label: t('Active Only') },
]);

const segment =
    'inline-flex h-7 items-center gap-1.5 rounded-[5px] px-2.5 text-[13px] text-muted hover:bg-surface hover:text-ink disabled:pointer-events-none disabled:opacity-40';

// Shared by the column header and every row of the tree.
const columns = '--account-columns: minmax(22rem,1fr) 7rem minmax(10rem,13rem) 6.5rem 3rem';
</script>

<template>
    <PageHeader :title="t('Chart of Accounts')" :description="description">
        <template #actions>
            <Button v-if="can('Account Create')" variant="primary" :href="route('accounting.accounts.add')"><Plus class="size-4" />{{ t('Add Account') }}</Button>
        </template>
    </PageHeader>

    <div class="space-y-5">
        <AccountClassBand :summary="summary" :selected="filters.type" @select="(value) => (filters.type = value)" />

        <div class="overflow-hidden rounded-xl border border-line bg-card shadow-card">
            <div class="flex flex-wrap items-center gap-3 border-b border-line p-3 lg:px-4">
                <label class="relative w-full sm:w-80">
                    <span class="sr-only">{{ t('Search accounts') }}</span>
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('Search by code or name')"
                        class="h-control w-full rounded-control border border-line bg-surface ps-9 pe-3 text-sm outline-none placeholder:text-gray-400 focus:border-brand-300 focus:bg-card focus:shadow-focus-ring"
                    />
                </label>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="chip in chips"
                        :key="chip.key"
                        type="button"
                        class="inline-flex h-control items-center gap-1.5 rounded-full border px-3 text-[13px] font-medium transition-colors"
                        :class="
                            filters[chip.key]
                                ? 'border-brand-200 bg-brand-50 text-brand-700 dark:border-brand-500/30 dark:bg-brand-500/15 dark:text-brand-300'
                                : 'border-line bg-card text-muted hover:border-gray-300 hover:text-ink dark:hover:border-white/20'
                        "
                        :aria-pressed="filters[chip.key]"
                        @click="filters[chip.key] = !filters[chip.key]"
                    >
                        <Check v-if="filters[chip.key]" class="size-3.5" />
                        {{ chip.label }}
                    </button>

                    <button v-if="filtered" type="button" class="inline-flex h-control items-center gap-1.5 px-2 text-[13px] font-medium text-muted hover:text-ink" @click="resetFilters">
                        <Returns class="size-3.5" />{{ t('Reset') }}
                    </button>
                </div>

                <div class="ms-auto flex items-center rounded-control border border-line p-0.5">
                    <button type="button" :class="segment" :disabled="expandAll" @click="expandEverything"><ChevronDown class="size-3.5" />{{ t('Expand All') }}</button>
                    <button type="button" :class="segment" :disabled="expandAll" @click="collapseEverything"><ChevronUp class="size-3.5" />{{ t('Collapse All') }}</button>
                </div>
            </div>

            <div class="overflow-x-auto transition-opacity" :class="{ 'opacity-50': loading }" :style="columns" :aria-busy="loading">
                <div class="lg:min-w-[60rem]">
                    <div class="hidden gap-4 border-b border-line px-4 py-2.5 text-xs font-medium text-muted lg:grid lg:grid-cols-(--account-columns)">
                        <div class="ps-14">{{ t('Account') }}</div>
                        <div>{{ t('Normal Balance') }}</div>
                        <div class="pe-10 text-end">{{ t('Balance') }}</div>
                        <div>{{ t('Status') }}</div>
                        <div><span class="sr-only">{{ t('Actions') }}</span></div>
                    </div>

                    <div class="divide-y divide-line">
                        <AccountTreeNode v-for="node in tree" :key="node.id" :node="node" :expand-all="expandAll" :expanded="expanded" @toggle="toggle" @delete="remove" />
                    </div>

                    <div v-if="!tree.length" class="flex flex-col items-center gap-3 px-6 py-14 text-center">
                        <p class="text-sm font-medium text-ink">{{ t('No accounts found') }}</p>
                        <template v-if="filtered">
                            <p class="text-sm text-muted">{{ t('No account matches these filters.') }}</p>
                            <Button size="sm" @click="resetFilters">{{ t('Reset') }}</Button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

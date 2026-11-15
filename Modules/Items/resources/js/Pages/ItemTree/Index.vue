<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { Layers, MoreVertical, Package, Pencil, Plus, Returns, Search, Settings, Tag, Users, X } from '@/lib/icons';
import { route } from '@/lib/route';

import CategoryCardModal from './parts/CategoryCardModal.vue';
import ItemCardModal from './parts/ItemCardModal.vue';
import ItemTreeCategory from './parts/ItemTreeCategory.vue';
import MoveModal from './parts/MoveModal.vue';
import NodeMenu from './parts/NodeMenu.vue';
import QuantityModal from './parts/QuantityModal.vue';
import { getJson, type CardRequest, type CategoryCardRequest, type NodeAction, type NodeKind, type NodeMenu as NodeMenuData, type SavedNode, type TreeCategory, type TreeRow } from './parts/types';
import { createItemTree, provideItemTree, type TreeFilters } from './parts/useItemTree';

defineOptions({ layout: AdminLayout });

/**
 * The items tree as in the old system: the tree on one side, each node with its number and name;
 * on the other the search, the table of the chosen node (the node itself as a dark row, then its
 * children) and the action buttons; the item card, the category card and the quantity window.
 * Every node has a right-click menu (also the ⋮ button) by its type and the user's permissions.
 */
const props = defineProps<{
    roots: TreeCategory[];
    filters: TreeFilters;
    pageSize: number;
    saved: SavedNode | null;
}>();

const tree = createItemTree(props.roots, props.filters, props.pageSize);

/* ---------- windows ---------- */

const cardOpen = ref(false);
const cardRequest = ref<CardRequest | null>(null);
const categoryCardOpen = ref(false);
const categoryCardRequest = ref<CategoryCardRequest | null>(null);
const quantityOpen = ref(false);
const quantityProductId = ref<number | null>(null);
const moveOpen = ref(false);
const moveNode = ref<{ kind: 'category' | 'product'; id: number; label: string } | null>(null);
const cardTab = ref('information');

const cardContext = computed(() => ({
    categoryId: tree.selectedCategoryId.value,
    productId: tree.selectedProductId.value,
    highlightedId: tree.highlightedId.value,
}));

function openCard(request: CardRequest = {}): void {
    cardRequest.value = { ...request };
    cardOpen.value = true;
}

function openItemCard(productId: number, tab?: string): void {
    if (!can('Product Update')) return;

    openCard({ productId, tab });
}

function openCategoryCard(request: CategoryCardRequest): void {
    categoryCardRequest.value = { ...request };
    categoryCardOpen.value = true;
}

function newCategoryUnderSelection(): void {
    const header = tree.selectedCategory.value;

    openCategoryCard({ parentId: tree.selectedCategoryId.value, parent: header ? { code: header.code, name: header.name } : null });
}

/** Double click on a category of the tree: its twin's card, or the category card. */
async function openCategoryNode(categoryId: number): Promise<void> {
    const node = await getJson<NodeMenuData>(route('products.tree.actions', { kind: 'category', id: categoryId }));

    if (node.type === 'main' && node.product_id) openItemCard(node.product_id);
    else if (can('Category Update')) openCategoryCard({ id: categoryId });
}

/* ---------- the right-click menu ---------- */

const menu = ref({ open: false, x: 0, y: 0, loading: false, node: null as NodeMenuData | null });
let menuRequest = 0;

async function openMenu(event: MouseEvent, kind: NodeKind, id?: number | null): Promise<void> {
    const anchor = event.currentTarget instanceof HTMLElement && event.type === 'click' ? event.currentTarget.getBoundingClientRect() : null;
    const request = ++menuRequest;

    menu.value = {
        open: true,
        x: anchor ? anchor.left : event.clientX,
        y: anchor ? anchor.bottom + 4 : event.clientY,
        loading: true,
        node: null,
    };

    try {
        const node = await getJson<NodeMenuData>(route('products.tree.actions', { kind, id: id ?? undefined }));

        if (request === menuRequest) menu.value = { ...menu.value, loading: false, node };
    } catch {
        if (request === menuRequest) menu.value = { ...menu.value, open: false, loading: false };
    }
}

const visitOptions = { preserveState: true, preserveScroll: true, onSuccess: () => void tree.refresh() };

async function runAction(action: NodeAction): Promise<void> {
    const node = menu.value.node;

    if (!node) return;
    if (action.confirm && !(await confirm({ title: action.confirm, description: [node.code, node.name].filter(Boolean).join(' - '), tone: action.key === 'delete' ? 'danger' : 'primary' }))) return;

    const label = [node.code, node.name].filter(Boolean).join(' - ');

    switch (action.key) {
        case 'edit':
            if (node.product_id) openItemCard(node.product_id);
            else if (node.category_id) openCategoryCard({ id: node.category_id });
            break;
        case 'add_category':
            openCategoryCard({ parentId: node.category_id, parent: node.category_id ? { code: node.code, name: node.name ?? '' } : null });
            break;
        case 'add_item':
            openCard({ categoryId: node.category_id, underCategory: true });
            break;
        case 'add_piece':
            openCard({ fixedMainId: node.product_id });
            break;
        case 'copy':
            router.post(route('products.tree.items.copy', node.product_id!), {}, visitOptions);
            break;
        case 'delete':
            router.delete(node.type === 'category' ? route('products.tree.categories.destroy', node.category_id!) : route('products.tree.items.destroy', node.product_id!), {
                ...visitOptions,
                onSuccess: () => {
                    if (tree.highlightedId.value === node.product_id) tree.highlightedId.value = null;
                    if (tree.selectedProductId.value === node.product_id) tree.selectedProductId.value = null;
                    if (node.type === 'category' && tree.selectedCategoryId.value === node.category_id) tree.selectedCategoryId.value = null;
                    void tree.refresh();
                },
            });
            break;
        case 'move':
            moveNode.value = node.type === 'category' ? { kind: 'category', id: node.category_id!, label } : { kind: 'product', id: node.product_id!, label };
            moveOpen.value = true;
            break;
        case 'movement':
            router.visit(route('accounting.inventory.item-card', { product: node.product_id }));
            break;
        case 'stock_report':
            router.visit(route('accounting.inventory.stock.index'));
            break;
    }
}

provideItemTree(tree, { openMenu, openItemCard, openCategoryNode });

/* ---------- after a save ---------- */

watch(
    () => props.roots,
    (roots) => {
        tree.roots.value = roots;
    },
);

watch(
    () => props.saved,
    async (saved) => {
        if (!saved) return;

        if (saved.kind === 'category') {
            await tree.enterCategory(saved.id, saved.path);
            return;
        }

        // The table shows the saved item in its node; the card stays open on it.
        const header = tree.rows.value[0];
        const twinOfSelected = header?.is_header && header.kind === 'product' && !tree.selectedProductId.value ? header.id : null;

        tree.search.value = '';
        tree.highlightedId.value = saved.id;
        tree.highlightedCategoryId.value = null;
        if (saved.main_product_id && saved.main_product_id !== twinOfSelected) tree.selectedProductId.value = saved.main_product_id;

        await tree.refresh();
        openCard({ productId: saved.id, tab: cardTab.value });
    },
);

/* ---------- the table and the search ---------- */

const searchInput = ref<HTMLInputElement>();
const cursor = ref(-1);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(tree.search, () => {
    cursor.value = -1;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(tree.restartSearch, 250);
});
watch([tree.searchPricing, tree.searchStatus, tree.searchInSelected], () => tree.restartSearch());

function focusSearch(): void {
    searchInput.value?.focus();
    searchInput.value?.select();
}

function moveCursor(step: number): void {
    const count = tree.rows.value.length;
    if (!count) return;

    cursor.value = (cursor.value + step + count) % count;
    void nextTick(() => document.querySelector(`[data-tree-row="${cursor.value}"]`)?.scrollIntoView({ block: 'nearest' }));
}

/** Enter on a result: the item card, or the category in the tree. */
function openCursor(): void {
    const row = tree.rows.value[Math.max(cursor.value, 0)];

    if (!row) return;
    if (row.kind === 'category') void tree.enterCategory(row.id);
    else openItemCard(row.id);
}

function clearSearchKey(): void {
    if (tree.search.value === '') {
        searchInput.value?.blur();
        return;
    }

    cursor.value = -1;
    tree.clearSearch();
}

function rowDoubleClick(row: TreeRow): void {
    if (row.kind === 'product') openItemCard(row.id);
    else if (row.is_header) {
        if (can('Category Update')) openCategoryCard({ id: row.id });
    } else void tree.enterCategory(row.id);
}

function isHighlighted(row: TreeRow): boolean {
    return row.kind === 'product' ? tree.highlightedId.value === row.id : tree.highlightedCategoryId.value === row.id;
}

function shortcut(event: KeyboardEvent): void {
    if (cardOpen.value || categoryCardOpen.value || quantityOpen.value || moveOpen.value) return;

    const target = event.target as HTMLElement;
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable;

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        focusSearch();
    } else if (event.key === '/' && !typing) {
        event.preventDefault();
        focusSearch();
    }
}

onMounted(() => {
    window.addEventListener('keydown', shortcut);
    if (tree.search.value.trim()) void tree.loadRows();
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', shortcut);
    clearTimeout(searchTimer);
});

/* ---------- the buttons ---------- */

function editTarget(): void {
    const target = tree.target.value;

    if (target.product) openItemCard(target.product);
    else if (target.category && can('Category Update')) openCategoryCard({ id: target.category });
}

function openQuantity(): void {
    quantityProductId.value = tree.target.value.product;
    quantityOpen.value = true;
}

const canEditTarget = computed(() => (tree.target.value.product ? can('Product Update') : tree.target.value.category ? can('Category Update') : false));

const chip = (on: boolean): string[] => [
    'rounded-full px-3 py-1 text-xs font-medium transition-colors',
    on ? 'bg-brand-500 text-white shadow-theme-xs' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10',
];
const pricingChips = computed(() => [
    { value: '', label: t('All') },
    { value: 'simple', label: t('By Quantity') },
    { value: 'weight', label: t('By Weight') },
]);
const statusChips = computed(() => [
    { value: '', label: t('All') },
    { value: '1', label: t('Active') },
    { value: '0', label: t('Inactive') },
]);
</script>

<template>
    <PageHeader :title="t('Items Tree')" :parents="[{ title: t('Products List'), href: route('products.index') }]">
        <template #actions>
            <Button v-if="can('Product Create')" variant="primary" @click="openCard()"><Plus class="size-4" />{{ t('New Item') }}</Button>
            <Button v-if="can('Category Create')" @click="newCategoryUnderSelection"><Layers class="size-4" />{{ t('New Category') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-4 lg:grid-cols-[24rem_minmax(0,1fr)]">
        <!-- The tree: each node with its number and name. Right-click on its empty part: the root menu. -->
        <section class="rounded-xl border border-line bg-card p-3 shadow-card" :aria-label="t('Items Tree')" @contextmenu.prevent="openMenu($event, 'root')">
            <div class="mb-2 flex items-center justify-between gap-2 border-b border-line pb-2">
                <h2 class="text-base font-semibold">{{ t('Items Tree') }}</h2>
                <button
                    v-if="can('Product Create') || can('Category Create')"
                    type="button"
                    class="inline-flex size-8 items-center justify-center rounded-lg text-muted hover:bg-surface hover:text-ink"
                    :aria-label="t('Actions')"
                    @click.stop="openMenu($event, 'root')"
                >
                    <MoreVertical class="size-5" />
                </button>
            </div>

            <div class="max-h-[70vh] min-h-[24rem] overflow-auto text-sm" role="tree" :aria-label="t('Items Tree')">
                <ItemTreeCategory v-for="node in tree.roots.value" :key="node.id" :node="node" :level="0" />
                <p v-if="!tree.roots.value.length" class="px-3 py-8 text-center text-sm text-muted">{{ t('No data available') }}</p>
            </div>
        </section>

        <div class="flex min-w-0 flex-col gap-4">
            <!-- Search: Ctrl+K or / to reach it, arrows to move, Enter to open, Esc to clear. -->
            <section class="rounded-xl border border-line bg-card p-4 shadow-card" role="search">
                <label for="items-tree-search" class="sr-only">{{ t('Search') }}</label>
                <div class="relative">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                    <input
                        id="items-tree-search"
                        ref="searchInput"
                        v-model="tree.search.value"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="t('Search items: name, number, barcode, category, brand...')"
                        aria-controls="items-tree-results"
                        class="h-11 w-full rounded-lg border border-line bg-card ps-10 pe-24 text-base outline-none focus:border-brand-300 focus:shadow-focus-ring"
                        @keydown.down.prevent="moveCursor(1)"
                        @keydown.up.prevent="moveCursor(-1)"
                        @keydown.enter.prevent="openCursor"
                        @keydown.esc.stop.prevent="clearSearchKey"
                    />
                    <div class="absolute inset-y-0 end-0 flex items-center gap-1.5 pe-2">
                        <span v-if="tree.rowsLoading.value" class="size-4 animate-spin rounded-full border-2 border-brand-500 border-e-transparent" aria-hidden="true" />
                        <button v-if="tree.search.value" type="button" class="rounded p-1 text-gray-400 hover:bg-surface hover:text-ink" :title="t('Clear search')" :aria-label="t('Clear search')" @click="clearSearchKey">
                            <X class="size-4" />
                        </button>
                        <kbd class="hidden rounded border border-line bg-surface px-1.5 py-0.5 font-sans text-[11px] text-muted sm:inline">Ctrl K</kbd>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <div class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="t('Type')">
                        <span class="text-xs text-muted">{{ t('Type') }}:</span>
                        <button v-for="option in pricingChips" :key="option.value" type="button" :class="chip(tree.searchPricing.value === option.value)" :aria-pressed="tree.searchPricing.value === option.value" @click="tree.searchPricing.value = option.value">{{ option.label }}</button>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="t('Status')">
                        <span class="text-xs text-muted">{{ t('Status') }}:</span>
                        <button v-for="option in statusChips" :key="option.value" type="button" :class="chip(tree.searchStatus.value === option.value)" :aria-pressed="tree.searchStatus.value === option.value" @click="tree.searchStatus.value = option.value">{{ option.label }}</button>
                    </div>
                    <label v-if="tree.selectedCategory.value" class="inline-flex cursor-pointer items-center gap-2 text-xs text-muted">
                        <input v-model="tree.searchInSelected.value" type="checkbox" class="size-4 rounded accent-brand-500" />
                        {{ t('Only inside «:category»', { category: tree.selectedCategory.value.name }) }}
                    </label>
                </div>

                <div v-if="tree.summary.value" class="mt-3 space-y-1 border-t border-line pt-2 text-xs" aria-live="polite">
                    <p v-if="tree.summary.value.corrected_from" class="text-warning-700 dark:text-warning-400">
                        {{ t('Showing results for «:corrected» instead of «:original» (keyboard language corrected).', { corrected: tree.summary.value.corrected_to ?? '', original: tree.summary.value.corrected_from }) }}
                    </p>
                    <p v-if="tree.summary.value.approximate" class="text-warning-700 dark:text-warning-400">{{ t('No item matches all the words; showing the closest results.') }}</p>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-muted">
                        <span v-if="tree.summary.value.count === 0">{{ t('No results') }}</span>
                        <span v-else-if="tree.summary.value.has_more">{{ t('More than :count results, sorted by relevance', { count: tree.summary.value.count }) }}</span>
                        <span v-else-if="tree.summary.value.count === 1">{{ t('One result') }}</span>
                        <span v-else>{{ t(':count results, sorted by relevance', { count: tree.summary.value.count }) }}</span>
                        <span class="hidden md:inline">{{ t('↑ ↓ to move, Enter to open, Esc to clear') }}</span>
                    </div>
                </div>
            </section>

            <!-- The node's table: the node itself as a dark row, then its children. -->
            <section class="min-h-[20rem] flex-1 overflow-hidden rounded-xl border border-line bg-card shadow-card">
                <div class="max-h-[52vh] overflow-auto transition-opacity" :class="{ 'opacity-60': tree.rowsLoading.value }">
                    <table id="items-tree-results" class="w-full min-w-[36rem] text-sm">
                        <thead class="sticky top-0 z-10">
                            <tr class="bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                <th class="w-36 px-3 py-2 text-start font-medium">{{ t('Item Number') }}</th>
                                <th class="w-40 px-3 py-2 text-start font-medium">{{ t('Barcode') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ t('Item Name') }}</th>
                                <th class="w-10 px-1 py-2"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in tree.rows.value"
                                :key="row.key"
                                :data-tree-row="index"
                                class="cursor-pointer border-b border-line select-none"
                                :class="[
                                    isHighlighted(row)
                                        ? 'bg-brand-500 text-white'
                                        : row.is_header
                                          ? 'bg-gray-900 font-semibold text-white dark:bg-black'
                                          : 'text-ink even:bg-brand-50/60 hover:bg-brand-100/60 dark:even:bg-brand-500/5 dark:hover:bg-brand-500/15',
                                    { 'font-semibold': row.is_main || row.kind === 'category', 'outline-2 -outline-offset-2 outline-warning-400': cursor === index },
                                ]"
                                :title="row.kind === 'product' ? t('Double-click to open the item card') : row.is_header ? t('Double-click to open the category card') : t('Double-click to open the category')"
                                @click="tree.selectRow(row)"
                                @dblclick="rowDoubleClick(row)"
                                @contextmenu.prevent.stop="openMenu($event, tree.menuKindOf(row), row.id)"
                            >
                                <td class="px-3 py-1.5 font-mono">
                                    <bdi v-if="row.html && row.sku" dir="ltr" v-html="row.html.sku" />
                                    <bdi v-else dir="ltr">{{ row.sku || '—' }}</bdi>
                                </td>
                                <td class="px-3 py-1.5 font-mono">
                                    <bdi v-if="row.html && row.barcode" dir="ltr" v-html="row.html.barcode" />
                                    <bdi v-else dir="ltr">{{ row.barcode || '—' }}</bdi>
                                </td>
                                <td class="px-3 py-1.5" :class="{ 'ps-7': row.is_sub }">
                                    <span class="inline-flex flex-wrap items-center gap-1.5">
                                        <Layers v-if="row.kind === 'category' && !row.is_header" class="size-4 shrink-0 opacity-70" />
                                        <Package v-else-if="row.is_main" class="size-4 shrink-0 opacity-70" />
                                        <span v-if="row.html" v-html="row.html.name" />
                                        <span v-else>{{ row.name }}</span>
                                        <span v-if="row.is_inactive" class="rounded-full bg-error-100 px-2 py-0.5 text-[11px] font-medium text-error-700 dark:bg-error-500/20 dark:text-error-300">{{ t('Inactive') }}</span>
                                    </span>
                                    <span v-if="row.matched_in" class="mt-0.5 block text-xs" :class="isHighlighted(row) ? 'text-white/80' : 'text-muted'">
                                        <Search class="inline size-3 align-baseline" /> {{ row.matched_in }}
                                    </span>
                                </td>
                                <td class="px-1 py-1">
                                    <button type="button" class="inline-flex size-7 items-center justify-center rounded opacity-70 hover:bg-black/10 hover:opacity-100" :aria-label="t('Actions')" @click.stop="openMenu($event, tree.menuKindOf(row), row.id)">
                                        <MoreVertical class="size-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!tree.rows.value.length">
                                <td colspan="4" class="px-3 py-10 text-center text-sm text-muted">
                                    <template v-if="tree.search.value.trim()">
                                        <span class="block font-medium text-ink">{{ t('No items match your search.') }}</span>
                                        <span class="mt-1 block text-xs">{{ t('Check the spelling, use fewer words, or clear the filters.') }}</span>
                                    </template>
                                    <template v-else>{{ t('Pick a category or a main item from the tree, or search.') }}</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <button v-if="tree.hasMoreRows.value" type="button" class="block w-full border-t border-line py-2 text-center text-xs font-medium text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10" @click="tree.showMore">
                    {{ t('Show more') }}
                </button>
            </section>

            <!-- The action buttons, as in the old system. -->
            <div class="flex flex-wrap gap-2">
                <Button v-if="can('Product Update') || can('Category Update')" :disabled="!canEditTarget" @click="editTarget"><Pencil class="size-4" />{{ t('Edit') }}</Button>
                <Button @click="tree.showAll"><Settings class="size-4" />{{ t('All') }}</Button>
                <Button v-if="can('Product Update')" :disabled="!tree.target.value.product" @click="openItemCard(tree.target.value.product!, 'suppliers')"><Users class="size-4" />{{ t('Supplier') }}</Button>
                <Button :disabled="!tree.target.value.product" @click="openQuantity"><Package class="size-4" />{{ t('Quantity') }}</Button>
                <Button v-if="tree.target.value.product && can('Product Units And Prices')" :href="route('products.units-and-prices', tree.target.value.product)"><Tag class="size-4" />{{ t('Units and Prices') }}</Button>
                <Button :href="route('products.index')"><Returns class="size-4" />{{ t('Back') }}</Button>
            </div>
        </div>
    </div>

    <NodeMenu v-model:open="menu.open" :x="menu.x" :y="menu.y" :loading="menu.loading" :actions="menu.node?.actions ?? []" @run="runAction" />
    <ItemCardModal v-model:open="cardOpen" v-model:tab="cardTab" :request="cardRequest" :context="cardContext" @create="openCard()" />
    <CategoryCardModal v-model:open="categoryCardOpen" :request="categoryCardRequest" />
    <QuantityModal v-model:open="quantityOpen" :product-id="quantityProductId" />
    <MoveModal v-model:open="moveOpen" :node="moveNode" @moved="tree.refresh" />
</template>

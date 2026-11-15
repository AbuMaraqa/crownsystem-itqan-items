import { router } from '@inertiajs/vue3';
import { computed, inject, provide, reactive, ref, shallowRef, type InjectionKey } from 'vue';

import { route } from '@/lib/route';

import { getJson, type CategoryChildren, type NodeKind, type RowsResponse, type SearchSummary, type TreeCategory, type TreePiece, type TreeRow } from './types';

export interface TreeFilters {
    q: string;
    type: string;
    status: string;
}

/**
 * The state of the items tree page, shared by the tree, the table and the windows: the tree loads
 * lazily (a category's children and items only when it opens, a main item's pieces only when it
 * opens), the table shows the chosen node (the node itself as a dark header row, then its children)
 * or the search results.
 */
export function createItemTree(initialRoots: TreeCategory[], filters: TreeFilters, pageSize: number) {
    const roots = shallowRef<TreeCategory[]>(initialRoots);
    const expandedCategories = ref(new Set<number>());
    const expandedProducts = ref(new Set<number>());
    const children = reactive(new Map<number, CategoryChildren & { limit: number }>());
    const pieces = reactive(new Map<number, TreePiece[]>());
    const loading = ref(new Set<string>());

    const activeNode = ref<string | null>(null);
    const selectedCategoryId = ref<number | null>(null);
    const selectedProductId = ref<number | null>(null);
    const highlightedId = ref<number | null>(null);
    const highlightedCategoryId = ref<number | null>(null);

    const search = ref(filters.q);
    const searchPricing = ref(filters.type);
    const searchStatus = ref(filters.status);
    const searchInSelected = ref(false);
    const tableLimit = ref(pageSize);

    const rows = shallowRef<TreeRow[]>([]);
    const hasMoreRows = ref(false);
    const summary = shallowRef<SearchSummary | null>(null);
    const rowsLoading = ref(false);
    let rowsRequest = 0;

    function busy(key: string, on: boolean): void {
        const next = new Set(loading.value);

        if (on) next.add(key);
        else next.delete(key);

        loading.value = next;
    }

    function toggled(set: Set<number>, id: number, open?: boolean): Set<number> {
        const next = new Set(set);
        const shouldOpen = open ?? !next.has(id);

        if (shouldOpen) next.add(id);
        else next.delete(id);

        return next;
    }

    /* ---------- the tree ---------- */

    async function loadChildren(categoryId: number, limit?: number): Promise<void> {
        const size = limit ?? children.get(categoryId)?.limit ?? pageSize;
        busy(`c${categoryId}`, true);

        try {
            const data = await getJson<CategoryChildren>(route('products.tree.children', { category: categoryId, limit: size }));
            children.set(categoryId, { ...data, limit: size });
        } finally {
            busy(`c${categoryId}`, false);
        }
    }

    async function loadPieces(productId: number): Promise<void> {
        busy(`p${productId}`, true);

        try {
            pieces.set(productId, await getJson<TreePiece[]>(route('products.tree.pieces', productId)));
        } finally {
            busy(`p${productId}`, false);
        }
    }

    function toggleCategory(categoryId: number): void {
        const open = !expandedCategories.value.has(categoryId);
        expandedCategories.value = toggled(expandedCategories.value, categoryId, open);

        if (open && !children.has(categoryId)) void loadChildren(categoryId);

        selectCategory(categoryId);
    }

    function toggleProduct(productId: number, categoryId: number | null): void {
        const open = !expandedProducts.value.has(productId);
        expandedProducts.value = toggled(expandedProducts.value, productId, open);

        if (open && !pieces.has(productId)) void loadPieces(productId);

        selectProduct(productId, { categoryId, isMain: true });
    }

    function showMoreInTree(categoryId: number): void {
        void loadChildren(categoryId, (children.get(categoryId)?.limit ?? pageSize) + pageSize);
    }

    /* ---------- choosing a node ---------- */

    function resetTable(): void {
        search.value = '';
        tableLimit.value = pageSize;
    }

    function selectCategory(categoryId: number): void {
        activeNode.value = `c${categoryId}`;
        selectedCategoryId.value = categoryId;
        selectedProductId.value = null;
        highlightedId.value = null;
        highlightedCategoryId.value = null;
        resetTable();
        void loadRows();
    }

    /**
     * An item picked in the tree. A main item shows its pieces; another item is shown among its
     * siblings in the node it was picked from (the category, or the main item `mainId`).
     */
    function selectProduct(productId: number, context: { categoryId?: number | null; mainId?: number | null; isMain?: boolean; ownMainId?: number | null }): void {
        activeNode.value = `p${productId}`;
        highlightedId.value = productId;
        highlightedCategoryId.value = null;
        resetTable();

        if (context.isMain) selectedProductId.value = productId;
        else if (context.mainId) selectedProductId.value = context.mainId;
        else if (!context.categoryId && context.ownMainId) selectedProductId.value = context.ownMainId;
        else selectedProductId.value = null;

        if (context.categoryId) selectedCategoryId.value = context.categoryId;

        void loadRows();
    }

    /** Opens a category in the tree with its parents, and chooses it. */
    async function enterCategory(categoryId: number, path?: number[]): Promise<void> {
        const ids = path ?? (await getJson<number[]>(route('products.tree.path', categoryId)));

        for (const id of ids) {
            expandedCategories.value = toggled(expandedCategories.value, id, true);
            if (id !== categoryId || !children.has(id)) await loadChildren(id);
        }

        selectCategory(categoryId);
    }

    function selectRow(row: TreeRow): void {
        if (row.kind === 'product') {
            highlightedId.value = row.id;
            highlightedCategoryId.value = null;
        } else {
            highlightedCategoryId.value = row.id;
            highlightedId.value = null;
        }
    }

    /** "All": the tree from its start, nothing chosen, no search. */
    function showAll(): void {
        expandedCategories.value = new Set();
        expandedProducts.value = new Set();
        children.clear();
        pieces.clear();
        activeNode.value = null;
        selectedCategoryId.value = null;
        selectedProductId.value = null;
        highlightedId.value = null;
        highlightedCategoryId.value = null;
        resetTable();
        void loadRows();
    }

    /* ---------- the table ---------- */

    async function loadRows(): Promise<void> {
        const term = search.value.trim();
        const request = ++rowsRequest;

        if (!term && !selectedProductId.value && !selectedCategoryId.value) {
            rows.value = [];
            hasMoreRows.value = false;
            summary.value = null;
            return;
        }

        const query: Record<string, string | number> = { limit: tableLimit.value };

        if (term) {
            query.q = term;
            if (searchPricing.value) query.type = searchPricing.value;
            if (searchStatus.value) query.status = searchStatus.value;
            if (searchInSelected.value && selectedCategoryId.value) {
                query.category = selectedCategoryId.value;
                query.inside = 1;
            }
        } else if (selectedProductId.value) {
            query.product = selectedProductId.value;
        } else if (selectedCategoryId.value) {
            query.category = selectedCategoryId.value;
        }

        rowsLoading.value = true;

        try {
            const data = await getJson<RowsResponse>(route('products.tree.rows', query));

            // A newer search replaced this one.
            if (request !== rowsRequest) return;

            rows.value = data.rows;
            hasMoreRows.value = data.has_more;
            summary.value = data.summary;
        } finally {
            if (request === rowsRequest) rowsLoading.value = false;
        }
    }

    function restartSearch(): void {
        tableLimit.value = pageSize;
        highlightedId.value = null;
        highlightedCategoryId.value = null;
        void loadRows();

        // The search lives in the page's address: kept on reload, can be shared.
        const params: Record<string, string> = {};
        if (search.value.trim()) params.q = search.value.trim();
        if (searchPricing.value) params.type = searchPricing.value;
        if (searchStatus.value) params.status = searchStatus.value;

        router.replace({ url: route('products.tree', params), preserveState: true, preserveScroll: true });
    }

    function clearSearch(): void {
        search.value = '';
        searchPricing.value = '';
        searchStatus.value = '';
        searchInSelected.value = false;
        restartSearch();
    }

    function showMore(): void {
        tableLimit.value += pageSize;
        void loadRows();
    }

    /** What the buttons act on: the highlighted row, else the node shown as the table's header. */
    const target = computed<{ product: number | null; category: number | null }>(() => {
        if (highlightedId.value || highlightedCategoryId.value) {
            return { product: highlightedId.value, category: highlightedCategoryId.value };
        }

        const header = search.value.trim() === '' ? rows.value[0] : undefined;

        if (!header || !header.is_header) return { product: null, category: null };

        return header.kind === 'product' ? { product: header.id, category: null } : { product: null, category: header.id };
    });

    /** The chosen category's number and name (the header row when it is a category). */
    const selectedCategory = computed(() => {
        const header = rows.value[0];

        return header && header.is_header && header.kind === 'category' && header.id === selectedCategoryId.value ? { code: header.sku, name: header.name ?? '' } : null;
    });

    /** After a write: the open branches, the open main items and the table load again. */
    async function refresh(): Promise<void> {
        await Promise.all([
            ...[...children.keys()].filter((id) => expandedCategories.value.has(id)).map((id) => loadChildren(id)),
            ...[...pieces.keys()].filter((id) => expandedProducts.value.has(id)).map((id) => loadPieces(id)),
            loadRows(),
        ]);

        // Closed branches load fresh when they open again.
        for (const id of [...children.keys()]) if (!expandedCategories.value.has(id)) children.delete(id);
        for (const id of [...pieces.keys()]) if (!expandedProducts.value.has(id)) pieces.delete(id);
    }

    function menuKindOf(row: TreeRow): NodeKind {
        return row.kind === 'category' ? 'category' : 'product';
    }

    return {
        pageSize,
        roots,
        expandedCategories,
        expandedProducts,
        children,
        pieces,
        loading,
        activeNode,
        selectedCategoryId,
        selectedProductId,
        highlightedId,
        highlightedCategoryId,
        search,
        searchPricing,
        searchStatus,
        searchInSelected,
        tableLimit,
        rows,
        hasMoreRows,
        summary,
        rowsLoading,
        target,
        selectedCategory,
        loadChildren,
        toggleCategory,
        toggleProduct,
        showMoreInTree,
        selectCategory,
        selectProduct,
        enterCategory,
        selectRow,
        showAll,
        loadRows,
        restartSearch,
        clearSearch,
        showMore,
        refresh,
        menuKindOf,
    };
}

export type ItemTree = ReturnType<typeof createItemTree>;

/** The page's handlers the tree nodes call: menus, cards. */
export interface ItemTreeActions {
    openMenu: (event: MouseEvent, kind: NodeKind, id?: number | null) => void;
    openItemCard: (productId: number) => void;
    openCategoryNode: (categoryId: number) => void;
}

const treeKey: InjectionKey<{ tree: ItemTree; actions: ItemTreeActions }> = Symbol('itemTree');

export function provideItemTree(tree: ItemTree, actions: ItemTreeActions): void {
    provide(treeKey, { tree, actions });
}

export function useItemTree(): { tree: ItemTree; actions: ItemTreeActions } {
    const context = inject(treeKey);

    if (!context) throw new Error('useItemTree() outside the items tree page');

    return context;
}

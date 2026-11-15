import { router } from '@inertiajs/vue3';
import { inject, provide, reactive, ref, type InjectionKey } from 'vue';

import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { CategoryBranch, CategoryNode } from './types';

/**
 * The categories tree page's state: open nodes and what they loaded, the dragged node and where it
 * would drop. Closing a node closes what is under it too.
 */
export function createCategoryTree(pageSize: number, showInactive: () => boolean, searching: () => boolean) {
    const expanded = ref(new Set<number>());
    const branches = reactive(new Map<number, CategoryBranch>());
    const loading = ref(new Set<number>());
    const dragId = ref<number | null>(null);
    const over = ref<string | null>(null);

    async function load(id: number, limit?: number): Promise<void> {
        const size = limit ?? branches.get(id)?.limit ?? pageSize;
        loading.value = new Set(loading.value).add(id);

        try {
            const response = await fetch(route('categories.tree.children', { category: id, limit: size, show_inactive: showInactive() ? 1 : 0 }), { headers: { Accept: 'application/json' } });

            if (response.ok) branches.set(id, { ...((await response.json()) as Omit<CategoryBranch, 'limit'>), limit: size });
        } finally {
            const next = new Set(loading.value);
            next.delete(id);
            loading.value = next;
        }
    }

    function descendants(id: number, ids: Set<number> = new Set()): Set<number> {
        for (const child of branches.get(id)?.children ?? []) {
            if (!ids.has(child.id)) {
                ids.add(child.id);
                descendants(child.id, ids);
            }
        }

        return ids;
    }

    function toggle(id: number): void {
        const next = new Set(expanded.value);

        if (next.has(id)) {
            next.delete(id);
            descendants(id).forEach((child) => next.delete(child));
        } else {
            next.add(id);
            if (!branches.has(id)) void load(id);
        }

        expanded.value = next;
    }

    function collapseAll(): void {
        expanded.value = new Set();
    }

    function isOpen(id: number): boolean {
        return searching() || expanded.value.has(id);
    }

    function showMore(id: number): void {
        void load(id, (branches.get(id)?.limit ?? pageSize) + pageSize);
    }

    /** After a write: the roots (page props) and every open branch load again. */
    function reload(): void {
        router.reload({
            only: ['tree'],
            onSuccess: () => {
                for (const id of [...branches.keys()]) {
                    if (expanded.value.has(id)) void load(id);
                    else branches.delete(id);
                }
            },
        });
    }

    const options = { preserveScroll: true, preserveState: true, onSuccess: reload };

    /** Drops the dragged node under `parentId` (null: the root) at `position` among its siblings. */
    function drop(parentId: number | null, position: number): void {
        const id = dragId.value;
        dragId.value = null;
        over.value = null;

        if (id === null || id === parentId) return;

        router.put(route('categories.tree.move', id), { parent_id: parentId, position }, options);
    }

    function toggleStatus(node: CategoryNode): void {
        router.put(route('categories.tree.status', node.id), {}, options);
    }

    async function remove(node: CategoryNode): Promise<void> {
        if (await confirm({ title: t('Are you sure you want to delete this category?'), description: node.name })) {
            router.delete(route('categories.destroy', node.id), options);
        }
    }

    return { expanded, branches, loading, dragId, over, load, toggle, collapseAll, isOpen, showMore, reload, drop, toggleStatus, remove, searching };
}

export type CategoryTree = ReturnType<typeof createCategoryTree>;

const key: InjectionKey<CategoryTree> = Symbol('categoryTree');

export function provideCategoryTree(tree: CategoryTree): void {
    provide(key, tree);
}

export function useCategoryTree(): CategoryTree {
    const tree = inject(key);

    if (!tree) throw new Error('useCategoryTree() outside the categories tree page');

    return tree;
}

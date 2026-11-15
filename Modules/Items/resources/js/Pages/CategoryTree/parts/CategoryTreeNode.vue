<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { can, t } from '@/lib/i18n';
import { ChevronDown, ChevronRight, GripVertical, Layers, Package, Pencil, QrCode, Settings, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

import type { CategoryNode } from './types';
import { useCategoryTree } from './useCategoryTree';

/**
 * A category of the categories tree: drag handle, open/close (opening loads its children and items),
 * image, name and item count, and its actions. Dropping on the bar above a node puts the dragged one
 * before it among its siblings; dropping on the node puts it inside.
 */
defineOptions({ name: 'CategoryTreeNode' });

const props = defineProps<{ node: CategoryNode; level: number; parentId: number | null; index: number }>();

const tree = useCategoryTree();

const open = computed(() => tree.isOpen(props.node.id));
const branch = computed(() => tree.branches.get(props.node.id));
const children = computed(() => (tree.searching() ? props.node.children : (branch.value?.children ?? [])));
const products = computed(() => (open.value && !tree.searching() ? (branch.value?.products ?? []) : []));
const hidden = computed(() => Math.max(props.node.products_count - products.value.length, 0));
const canExpand = computed(() => props.node.has_children || props.node.products_count > 0);
const canDrop = computed(() => can('Category Update') && tree.dragId.value !== null && tree.dragId.value !== props.node.id);
const indent = (level: number): Record<string, string> => ({ marginInlineStart: `${level * 24}px` });

function dragOver(zone: string): void {
    if (canDrop.value) tree.over.value = zone;
}
</script>

<template>
    <div role="treeitem" :aria-expanded="canExpand ? open : undefined" :aria-level="level + 1">
        <!-- The bar before this node: reorders among siblings. -->
        <div
            class="h-1.5 rounded transition-colors"
            :class="tree.over.value === `before-${node.id}` ? 'bg-brand-500' : 'bg-transparent'"
            @dragover.prevent="dragOver(`before-${node.id}`)"
            @dragleave="tree.over.value = null"
            @drop.prevent="tree.drop(parentId, index)"
        />

        <div
            :draggable="can('Category Update')"
            class="group flex items-center gap-2 rounded-lg border border-transparent px-2 py-2 transition-colors hover:bg-surface"
            :class="{ 'border-brand-300 bg-brand-50 dark:bg-brand-500/10': tree.over.value === `inside-${node.id}`, 'opacity-40': tree.dragId.value === node.id }"
            :style="indent(level)"
            @dragstart="tree.dragId.value = node.id"
            @dragend="
                tree.dragId.value = null;
                tree.over.value = null;
            "
            @dragover.prevent="dragOver(`inside-${node.id}`)"
            @dragleave="tree.over.value = null"
            @drop.prevent="tree.drop(node.id, 0)"
        >
            <span v-if="can('Category Update')" class="cursor-grab text-gray-300 group-hover:text-gray-400" :title="t('Drag to move')"><GripVertical class="size-4" /></span>

            <button
                v-if="canExpand && !tree.searching()"
                type="button"
                class="inline-flex size-6 shrink-0 items-center justify-center rounded text-muted hover:text-ink"
                :aria-label="open ? t('Collapse') : t('Expand')"
                :aria-expanded="open"
                @click="tree.toggle(node.id)"
            >
                <span v-if="tree.loading.value.has(node.id)" class="size-3.5 animate-spin rounded-full border-2 border-current border-e-transparent" />
                <ChevronDown v-else-if="open" class="size-4" />
                <ChevronRight v-else class="size-4 rtl:rotate-180" />
            </button>
            <span v-else class="inline-flex size-6 shrink-0 items-center justify-center"><span class="size-1.5 rounded-full bg-gray-300 dark:bg-gray-600" /></span>

            <img v-if="node.image" :src="node.image" alt="" class="size-8 shrink-0 rounded object-cover" />
            <span v-else class="inline-flex size-8 shrink-0 items-center justify-center rounded bg-surface text-gray-400"><Layers class="size-4" /></span>

            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span class="truncate text-sm font-medium" :class="node.is_active ? 'text-ink' : 'text-gray-400 line-through'">{{ node.name }}</span>
                    <span v-if="node.products_count > 0" class="shrink-0 rounded-full bg-surface px-2 py-0.5 text-xs text-muted">{{ node.products_count }}</span>
                </div>
                <div class="truncate text-xs text-gray-400" dir="ltr">{{ node.slug }}</div>
            </div>

            <div class="flex shrink-0 items-center gap-1 transition-opacity focus-within:opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
                <template v-if="can('Category Update')">
                    <button type="button" class="rounded p-1 text-gray-400 hover:bg-surface hover:text-ink" :title="node.is_active ? t('Deactivate') : t('Activate')" :aria-label="node.is_active ? t('Deactivate') : t('Activate')" @click="tree.toggleStatus(node)">
                        <Settings class="size-4" />
                    </button>
                    <Link :href="route('categories.edit', node.id)" class="rounded p-1 text-gray-400 hover:bg-surface hover:text-brand-600" :title="t('Edit')" :aria-label="t('Edit')"><Pencil class="size-4" /></Link>
                </template>
                <button v-if="can('Category Delete')" type="button" class="rounded p-1 text-gray-400 hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10" :title="t('Delete')" :aria-label="t('Delete')" @click="tree.remove(node)">
                    <Trash2 class="size-4" />
                </button>
            </div>
        </div>

        <!-- The category's items as leaves, at the same indent as its sub-categories. -->
        <template v-if="products.length">
            <div v-for="product in products" :key="`p${product.id}`" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-surface" :style="indent(level + 1)">
                <span class="size-4 shrink-0" />
                <span class="inline-flex size-6 shrink-0 items-center justify-center"><span class="size-1.5 rounded-full bg-brand-300" /></span>
                <span class="inline-flex size-8 shrink-0 items-center justify-center rounded bg-surface text-gray-400"><Package class="size-4" /></span>
                <div class="min-w-0 flex-1">
                    <a v-if="can('Product Update')" :href="route('products.edit', product.id)" class="block truncate text-sm text-ink hover:text-brand-600">{{ product.name }}</a>
                    <span v-else class="block truncate text-sm text-ink">{{ product.name }}</span>
                    <div class="flex flex-wrap items-center gap-x-2 text-xs text-gray-400">
                        <bdi class="font-mono" dir="ltr">{{ product.sku || '—' }}</bdi>
                        <span v-if="product.barcode" class="inline-flex items-center gap-1 font-mono" :title="t('Barcode')"><QrCode class="size-3" /><bdi dir="ltr">{{ product.barcode }}</bdi></span>
                    </div>
                </div>
            </div>

            <div v-if="hidden > 0" class="flex items-center gap-2 px-2 py-1.5" :style="indent(level + 1)">
                <span class="size-4 shrink-0" />
                <span class="size-6 shrink-0" />
                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-1 text-xs font-medium text-muted hover:bg-surface disabled:opacity-60" :disabled="tree.loading.value.has(node.id)" @click="tree.showMore(node.id)">
                    <ChevronDown class="size-3.5" />{{ t('Show more (:count remaining)', { count: hidden }) }}
                </button>
            </div>
        </template>

        <div v-if="open && children.length" role="group">
            <CategoryTreeNode v-for="(child, childIndex) in children" :key="child.id" :node="child" :level="level + 1" :parent-id="node.id" :index="childIndex" />
        </div>
    </div>
</template>

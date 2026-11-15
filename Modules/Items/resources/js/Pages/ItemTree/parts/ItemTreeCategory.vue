<script setup lang="ts">
import { computed } from 'vue';

import { t } from '@/lib/i18n';
import { MinusSmall, MoreVertical, PlusSmall } from '@/lib/icons';

import type { TreeCategory } from './types';
import { useItemTree } from './useItemTree';

/**
 * A category of the items tree with its number and name, as in the old system; under it its
 * sub-categories then its items, and a main item opens on its pieces. Right-click or the ⋮ button
 * opens the node's menu; a double click opens its card.
 */
defineOptions({ name: 'ItemTreeCategory' });

const props = defineProps<{ node: TreeCategory; level: number }>();

const { tree, actions } = useItemTree();

const expanded = computed(() => tree.expandedCategories.value.has(props.node.id));
const branch = computed(() => tree.children.get(props.node.id));
const indent = (level: number): Record<string, string> => ({ paddingInlineStart: `${0.375 + level * 1.1}rem` });

const rowClass = (key: string): string[] => [
    'group flex cursor-pointer items-center gap-1.5 rounded-md px-1.5 select-none transition-colors outline-none focus-visible:ring-2 focus-visible:ring-brand-300',
    tree.activeNode.value === key ? 'bg-brand-500 text-white' : 'text-ink hover:bg-brand-50 dark:hover:bg-brand-500/10',
];

function onKey(event: KeyboardEvent, open: () => void, card: () => void): void {
    if (event.key === 'Enter') card();
    else if (event.key === ' ') {
        event.preventDefault();
        open();
    } else if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
        event.preventDefault();
        (event.currentTarget as HTMLElement).querySelector<HTMLButtonElement>('[data-node-menu]')?.click();
    }
}
</script>

<template>
    <div role="treeitem" :aria-expanded="expanded" :aria-level="level + 1">
        <div
            :class="[rowClass(`c${node.id}`), 'py-1']"
            :style="indent(level)"
            tabindex="0"
            :title="t('Double-click to open the card')"
            @click="tree.toggleCategory(node.id)"
            @dblclick="actions.openCategoryNode(node.id)"
            @contextmenu.prevent.stop="actions.openMenu($event, 'category', node.id)"
            @keydown="onKey($event, () => tree.toggleCategory(node.id), () => actions.openCategoryNode(node.id))"
        >
            <span class="inline-flex size-4 shrink-0 items-center justify-center rounded-sm border border-current/30 opacity-70">
                <span v-if="tree.loading.value.has(`c${node.id}`)" class="size-2.5 animate-spin rounded-full border border-current border-e-transparent" />
                <MinusSmall v-else-if="expanded" class="size-3.5" />
                <PlusSmall v-else class="size-3.5" />
            </span>
            <bdi v-if="node.code" class="shrink-0 font-mono text-xs opacity-70" dir="ltr">{{ node.code }}</bdi>
            <span class="min-w-0 flex-1 truncate">{{ node.name }}</span>
            <button
                type="button"
                data-node-menu
                class="inline-flex size-6 shrink-0 items-center justify-center rounded opacity-60 hover:bg-black/10 hover:opacity-100 focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-70"
                :aria-label="t('Actions')"
                @click.stop="actions.openMenu($event, 'category', node.id)"
            >
                <MoreVertical class="size-4" />
            </button>
        </div>

        <div v-if="expanded && branch" role="group">
            <ItemTreeCategory v-for="child in branch.categories" :key="`c${child.id}`" :node="child" :level="level + 1" />

            <div v-for="product in branch.products" :key="`p${product.id}`">
                <div
                    :class="[rowClass(`p${product.id}`), 'py-0.5']"
                    :style="indent(level + 1)"
                    tabindex="0"
                    role="treeitem"
                    :aria-expanded="product.has_sub_products ? tree.expandedProducts.value.has(product.id) : undefined"
                    :title="t('Double-click to open the item card')"
                    @click="product.has_sub_products ? tree.toggleProduct(product.id, node.id) : tree.selectProduct(product.id, { categoryId: node.id })"
                    @dblclick="actions.openItemCard(product.id)"
                    @contextmenu.prevent.stop="actions.openMenu($event, 'product', product.id)"
                    @keydown="onKey($event, () => (product.has_sub_products ? tree.toggleProduct(product.id, node.id) : tree.selectProduct(product.id, { categoryId: node.id })), () => actions.openItemCard(product.id))"
                >
                    <span v-if="product.has_sub_products" class="inline-flex size-4 shrink-0 items-center justify-center rounded-sm border border-current/30 opacity-70">
                        <span v-if="tree.loading.value.has(`p${product.id}`)" class="size-2.5 animate-spin rounded-full border border-current border-e-transparent" />
                        <MinusSmall v-else-if="tree.expandedProducts.value.has(product.id)" class="size-3.5" />
                        <PlusSmall v-else class="size-3.5" />
                    </span>
                    <span v-else class="w-4 shrink-0 text-center opacity-40" aria-hidden="true">┄</span>
                    <bdi class="shrink-0 font-mono text-xs opacity-70" dir="ltr">{{ product.sku }}</bdi>
                    <span class="min-w-0 flex-1 truncate" :class="{ 'font-medium': product.has_sub_products }">{{ product.name }}</span>
                    <button
                        type="button"
                        data-node-menu
                        class="inline-flex size-6 shrink-0 items-center justify-center rounded opacity-60 hover:bg-black/10 hover:opacity-100 focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-70"
                        :aria-label="t('Actions')"
                        @click.stop="actions.openMenu($event, 'product', product.id)"
                    >
                        <MoreVertical class="size-4" />
                    </button>
                </div>

                <!-- The pieces inside their main item. -->
                <template v-if="tree.expandedProducts.value.has(product.id)">
                    <div
                        v-for="piece in tree.pieces.get(product.id) ?? []"
                        :key="`s${piece.id}`"
                        :class="[rowClass(`p${piece.id}`), 'py-0.5 text-[13px]']"
                        :style="indent(level + 2)"
                        tabindex="0"
                        role="treeitem"
                        :title="t('Double-click to open the item card')"
                        @click="tree.selectProduct(piece.id, { mainId: product.id })"
                        @dblclick="actions.openItemCard(piece.id)"
                        @contextmenu.prevent.stop="actions.openMenu($event, 'product', piece.id)"
                        @keydown="onKey($event, () => tree.selectProduct(piece.id, { mainId: product.id }), () => actions.openItemCard(piece.id))"
                    >
                        <span class="w-4 shrink-0 text-center opacity-40" aria-hidden="true">┄</span>
                        <bdi class="shrink-0 font-mono text-xs opacity-70" dir="ltr">{{ piece.sku }}</bdi>
                        <span class="min-w-0 flex-1 truncate">{{ piece.name }}</span>
                        <button
                            type="button"
                            data-node-menu
                            class="inline-flex size-6 shrink-0 items-center justify-center rounded opacity-60 hover:bg-black/10 hover:opacity-100 focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-70"
                            :aria-label="t('Actions')"
                            @click.stop="actions.openMenu($event, 'product', piece.id)"
                        >
                            <MoreVertical class="size-4" />
                        </button>
                    </div>
                </template>
            </div>

            <button v-if="branch.has_more" type="button" class="block py-0.5 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400" :style="{ paddingInlineStart: `${0.375 + (level + 1) * 1.1 + 1.4}rem` }" @click="tree.showMoreInTree(node.id)">
                {{ t('Show more') }}
            </button>
        </div>
    </div>
</template>

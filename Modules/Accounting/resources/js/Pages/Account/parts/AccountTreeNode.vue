<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import DropdownItem from '@/Components/ui/DropdownItem.vue';
import Badge from '@/Components/ui/Badge.vue';
import { can, t } from '@/lib/i18n';
import { ChevronDown, ChevronRight, Layers, MinusSmall, Plus } from '@/lib/icons';
import { route } from '@/lib/route';
import RecordActions from '@/Components/table/RecordActions.vue';

import type { AccountNode } from './types';

defineOptions({ name: 'AccountTreeNode' });

const props = defineProps<{
    node: AccountNode;
    /** Every branch open (a filter that narrows the tree is on). */
    expandAll: boolean;
    expanded: Set<number>;
}>();

const emit = defineEmits<{ toggle: [id: number]; delete: [node: AccountNode] }>();

const isOpen = (): boolean => props.expandAll || props.expanded.has(props.node.id);
// Debit balances read green and credit amber, as the normal balance badges.
const directionClasses: Record<string, string> = {
    success: 'text-success-700 dark:text-success-400',
    warning: 'text-warning-700 dark:text-warning-400',
};
const directionClass = (): string => directionClasses[props.node.balance_direction?.tone ?? ''] ?? 'text-muted';
</script>

<template>
    <div>
        <div class="grid gap-3 px-4 py-3 lg:grid-cols-[minmax(20rem,1.8fr)_7rem_13rem_minmax(10rem,0.9fr)_7rem_3.5rem] transition-colors hover:bg-gray-50/70 lg:items-center lg:gap-4 dark:hover:bg-white/[0.02]">
            <div class="min-w-0">
                <div class="flex min-w-0 items-start gap-2" :style="{ paddingInlineStart: `${Math.max(node.level - 1, 0) * 1.25}rem` }">
                    <button
                        v-if="node.children.length"
                        type="button"
                        class="mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted hover:bg-surface hover:text-ink"
                        :aria-expanded="isOpen()"
                        :aria-label="isOpen() ? t('Collapse') : t('Expand')"
                        :title="isOpen() ? t('Collapse') : t('Expand')"
                        @click="emit('toggle', node.id)"
                    >
                        <ChevronDown v-if="isOpen()" class="size-4" />
                        <ChevronRight v-else class="size-4 rtl:rotate-180" />
                    </button>
                    <span v-else class="mt-0.5 inline-flex size-7 shrink-0 items-center justify-center text-gray-400" aria-hidden="true">
                        <Layers v-if="node.has_children" class="size-4" />
                        <MinusSmall v-else class="size-4" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 items-center gap-2">
                            <bdi class="shrink-0 font-mono text-sm font-semibold text-ink" dir="ltr">{{ node.code }}</bdi>
                            <Link
                                v-if="can('Account Update')"
                                :href="route('accounting.accounts.edit', node.id)"
                                :title="node.name"
                                class="block min-w-0 truncate text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                            >
                                {{ node.name }}
                            </Link>
                            <span v-else :title="node.name" class="block min-w-0 truncate text-sm font-medium text-ink">{{ node.name }}</span>
                        </div>
                        <p v-if="node.description" class="mt-1 max-w-2xl truncate text-xs text-muted">{{ node.description }}</p>
                    </div>
                </div>
            </div>

            <div>
                <Badge v-if="node.type" :tone="node.type.tone">{{ node.type.label }}</Badge>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="node.normal_balance" :tone="node.normal_balance.tone">{{ node.normal_balance.label }}</Badge>
                <Badge v-if="node.is_postable" tone="brand">{{ t('Postable') }}</Badge>
            </div>

            <div class="lg:text-end">
                <span class="mb-1 block text-xs font-medium text-muted lg:hidden">{{ t('Balance') }}</span>
                <span class="inline-flex items-baseline gap-1.5 font-mono text-sm font-semibold tabular-nums" :class="directionClass()">
                    <bdi dir="ltr">{{ node.balance }}</bdi>
                    <span v-if="node.balance_direction" class="font-sans text-xs font-medium">{{ node.balance_direction.label }}</span>
                </span>
            </div>

            <div>
                <Badge :tone="node.is_active ? 'success' : 'neutral'">{{ node.is_active ? t('Active') : t('Inactive') }}</Badge>
            </div>

            <div class="flex items-center lg:justify-end">
                <RecordActions
                    entity="Account"
                    routes="accounting.accounts"
                    :id="node.id"
                    :title="`${node.code} - ${node.name}`"
                    :edit-href="route('accounting.accounts.edit', node.id)"
                    :fields="[
                        { label: t('Code'), value: node.code },
                        { label: t('Name'), value: node.name },
                        { label: t('Type'), value: node.type?.label },
                        { label: t('Normal Balance'), value: node.normal_balance?.label },
                        { label: t('Balance'), value: node.balance_direction ? `${node.balance} ${node.balance_direction.label}` : node.balance },
                        { label: t('Status'), value: node.is_active ? t('Active') : t('Inactive') },
                        { label: t('Description'), value: node.description },
                    ]"
                    @delete="emit('delete', node)"
                >
                    <DropdownItem v-if="can('Account Create')" :href="route('accounting.accounts.add', { parent_id: node.id })"><Plus />{{ t('Add Child Account') }}</DropdownItem>
                </RecordActions>
            </div>
        </div>

        <div v-if="isOpen() && node.children.length">
            <AccountTreeNode
                v-for="child in node.children"
                :key="child.id"
                :node="child"
                :expand-all="expandAll"
                :expanded="expanded"
                @toggle="(id) => emit('toggle', id)"
                @delete="(account) => emit('delete', account)"
            />
        </div>
    </div>
</template>

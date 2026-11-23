<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import Badge from '@/Components/ui/Badge.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import { can, t } from '@/lib/i18n';
import { ChevronDown, ChevronRight, FileText, Folder, FolderOpen, Plus } from '@/lib/icons';
import { route } from '@/lib/route';

import { toneText, type AccountNode } from './types';

defineOptions({ name: 'AccountTreeNode' });

const props = defineProps<{
    node: AccountNode;
    /** Every branch open (a filter that narrows the tree is on). */
    expandAll: boolean;
    expanded: Set<number>;
}>();

const emit = defineEmits<{ toggle: [id: number]; delete: [node: AccountNode] }>();

const isOpen = computed(() => props.expandAll || props.expanded.has(props.node.id));
const depth = computed(() => Math.max(props.node.level - 1, 0));
const isRoot = computed(() => depth.value === 0);

// One step of the tree is 1.5rem, after the row's 1rem padding; the arrow's middle is 0.75rem in.
// A guide runs down under each ancestor's arrow; the last one bends into this row, up to the
// arrow, or up to the icon when there is no arrow.
const STEP = 1.5;
const guideAt = (d: number): string => `${1 + d * STEP + 0.75}rem`;
const tickWidth = computed(() => `${props.node.children.length ? STEP - 0.75 : STEP + 0.75}rem`);
</script>

<template>
    <div>
        <div
            class="relative grid gap-3 px-4 py-2.5 transition-colors lg:grid-cols-(--account-columns) lg:items-center lg:gap-4"
            :class="[isRoot ? 'bg-gray-50/70 dark:bg-white/[0.02]' : 'hover:bg-gray-50/60 dark:hover:bg-white/[0.02]', { 'opacity-60': !node.is_active }]"
        >
            <!-- Class rail on the top-level accounts. -->
            <span v-if="isRoot && node.type" class="absolute inset-y-0 start-0 w-[3px] bg-current" :class="toneText[node.type.tone]" aria-hidden="true" />

            <!-- Tree guides. -->
            <template v-if="!isRoot">
                <span
                    v-for="d in depth"
                    :key="d"
                    class="pointer-events-none absolute inset-y-0 hidden border-s border-gray-200 lg:block dark:border-white/10"
                    :style="{ insetInlineStart: guideAt(d - 1) }"
                    aria-hidden="true"
                />
                <span
                    class="pointer-events-none absolute top-1/2 hidden border-t border-gray-200 lg:block dark:border-white/10"
                    :style="{ insetInlineStart: guideAt(depth - 1), width: tickWidth }"
                    aria-hidden="true"
                />
            </template>

            <div class="flex min-w-0 items-center gap-2" :style="{ paddingInlineStart: `${depth * STEP}rem` }">
                <button
                    v-if="node.children.length"
                    type="button"
                    class="relative inline-flex h-7 shrink-0 items-center gap-1 rounded-md ps-0.5 pe-1 text-muted transition-colors hover:bg-surface hover:text-ink"
                    :aria-expanded="isOpen"
                    :aria-label="isOpen ? t('Collapse') : t('Expand')"
                    :title="isOpen ? t('Collapse') : t('Expand')"
                    @click="emit('toggle', node.id)"
                >
                    <span class="inline-flex size-5 items-center justify-center">
                        <ChevronDown v-if="isOpen" class="size-3.5" />
                        <ChevronRight v-else class="size-3.5 rtl:rotate-180" />
                    </span>
                    <span :class="node.type ? toneText[node.type.tone] : 'text-gray-400'">
                        <FolderOpen v-if="isOpen" class="size-[18px]" />
                        <Folder v-else class="size-[18px]" />
                    </span>
                </button>
                <!-- No arrow, the icon sits under its siblings' folders: a folder whose sub-accounts the filter hides, or a final account. -->
                <span v-else class="relative inline-flex h-7 shrink-0 items-center gap-1 ps-0.5 pe-1" aria-hidden="true">
                    <span class="size-5" />
                    <Folder v-if="node.has_children" class="size-[18px] text-gray-400" />
                    <FileText v-else class="size-[18px] text-gray-400 dark:text-gray-500" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <bdi dir="ltr" class="shrink-0 text-sm tabular-nums" :class="isRoot ? 'font-bold text-ink' : 'font-medium text-muted'">{{ node.code }}</bdi>
                        <Link
                            v-if="can('Account Update')"
                            :href="route('accounting.accounts.edit', node.id)"
                            :title="node.name"
                            class="block min-w-0 truncate text-ink decoration-brand-400 underline-offset-4 hover:text-brand-600 hover:underline dark:hover:text-brand-400"
                            :class="isRoot ? 'text-[15px] font-semibold' : 'text-sm'"
                        >
                            {{ node.name }}
                        </Link>
                        <span v-else :title="node.name" class="block min-w-0 truncate text-ink" :class="isRoot ? 'text-[15px] font-semibold' : 'text-sm'">{{ node.name }}</span>
                        <Badge v-if="node.is_postable" tone="brand" class="shrink-0">{{ t('Postable') }}</Badge>
                    </div>
                    <p v-if="node.description" class="mt-0.5 max-w-2xl truncate text-xs text-muted">{{ node.description }}</p>
                </div>
            </div>

            <div class="text-sm text-muted max-lg:hidden">{{ node.normal_balance?.label }}</div>

            <div class="lg:text-end">
                <span class="me-2 text-xs text-muted lg:hidden">{{ t('Balance') }}</span>
                <span class="inline-flex items-baseline gap-1.5 tabular-nums">
                    <bdi dir="ltr" class="text-sm" :class="[isRoot ? 'font-bold' : 'font-semibold', node.balance_direction ? 'text-ink' : 'text-gray-400 dark:text-gray-600']">{{ node.balance }}</bdi>
                    <span v-if="node.balance_direction" class="w-8 text-start text-xs font-medium" :class="toneText[node.balance_direction.tone]">{{ node.balance_direction.label }}</span>
                    <span v-else class="w-8" aria-hidden="true" />
                </span>
            </div>

            <div class="flex items-center gap-1.5 text-xs" :class="node.is_active ? 'text-muted' : 'text-gray-400'">
                <span class="size-1.5 rounded-full" :class="node.is_active ? 'bg-success-500' : 'bg-gray-300 dark:bg-white/25'" aria-hidden="true" />
                {{ node.is_active ? t('Active') : t('Inactive') }}
            </div>

            <div class="flex items-center max-lg:absolute max-lg:top-2 max-lg:end-2 lg:justify-end">
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

        <div v-if="isOpen && node.children.length" class="divide-y divide-line/60 border-t border-line/60">
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

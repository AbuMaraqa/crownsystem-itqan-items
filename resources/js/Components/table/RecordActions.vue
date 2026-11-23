<script setup lang="ts">
import { Comment, computed, Fragment, ref, Text, useSlots, type VNode } from 'vue';

import DropdownItem from '@/Components/ui/DropdownItem.vue';
import DropdownSeparator from '@/Components/ui/DropdownSeparator.vue';
import { can, t } from '@/lib/i18n';
import { Eye, History, Pencil, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

import RecordInfoModal from './RecordInfoModal.vue';
import RecordViewModal, { type ViewField } from './RecordViewModal.vue';
import RowMenu from './RowMenu.vue';

/**
 * The row actions of EVERY table, behind one ⋮ button and in one order:
 * Activity Log · View · Edit · (the table's own actions) · Delete.
 * Activity Log and View are basic (anyone who can open the list); Edit needs `<Entity> Update` and
 * Delete `<Entity> Delete`, and a row hides them when its state forbids it (`editable`, `deletable`).
 * A table's own actions (print, post, barcode...) go in the default slot as `DropdownItem`s.
 */
const props = withDefaults(
    defineProps<{
        /** The permission's entity: `Tag` in `Tag Update`. */
        entity: string;
        /** The route names' prefix: `tags` in `tags.info`. */
        routes: string;
        id: number;
        title: string;
        /** What View shows in its modal; ignored when `viewHref` opens a page instead. */
        fields?: ViewField[];
        viewHref?: string;
        /** Edit opens this page; without it the `edit` event fires (tables that edit in a modal). */
        editHref?: string;
        editable?: boolean;
        deletable?: boolean;
        /** Defaults to `route('<routes>.info', id)`. */
        infoUrl?: string;
    }>(),
    { fields: () => [], viewHref: undefined, editHref: undefined, editable: true, deletable: true, infoUrl: undefined },
);

const emit = defineEmits<{ edit: []; delete: [] }>();

const infoOpen = ref(false);
const viewOpen = ref(false);

const canSee = computed(() => can(`${props.entity} View List`));
const canEdit = computed(() => props.editable && can(`${props.entity} Update`));
const canDelete = computed(() => props.deletable && can(`${props.entity} Delete`));
const infoHref = computed(() => props.infoUrl ?? route(`${props.routes}.info`, props.id));

const slots = useSlots();

/** True when a node renders something: every `v-if` of the extra actions may be false. */
const renders = (nodes: VNode[]): boolean =>
    nodes.some((node) => {
        if (node.type === Comment) return false;
        if (node.type === Text) return String(node.children ?? '').trim() !== '';
        if (node.type === Fragment) return Array.isArray(node.children) && renders(node.children as VNode[]);

        return true;
    });

const hasExtra = (): boolean => renders(slots.default?.() ?? []);
const hasBasic = computed(() => canSee.value || canEdit.value);
</script>

<template>
    <RowMenu v-if="hasBasic || canDelete || hasExtra()">
        <template v-if="canSee">
            <DropdownItem @select="infoOpen = true"><History />{{ t('Activity Log') }}</DropdownItem>
            <DropdownItem v-if="viewHref" :href="viewHref"><Eye />{{ t('View') }}</DropdownItem>
            <DropdownItem v-else @select="viewOpen = true"><Eye />{{ t('View') }}</DropdownItem>
        </template>
        <template v-if="canEdit">
            <DropdownItem v-if="editHref" :href="editHref"><Pencil />{{ t('Edit') }}</DropdownItem>
            <DropdownItem v-else @select="emit('edit')"><Pencil />{{ t('Edit') }}</DropdownItem>
        </template>
        <template v-if="hasExtra()">
            <DropdownSeparator v-if="hasBasic" />
            <slot />
        </template>
        <template v-if="canDelete">
            <DropdownSeparator v-if="hasBasic || hasExtra()" />
            <DropdownItem destructive @select="emit('delete')"><Trash2 />{{ t('Delete') }}</DropdownItem>
        </template>
    </RowMenu>

    <!-- Rendered only once opened: a table of 100 rows does not mount 200 modals. -->
    <RecordInfoModal v-if="canSee && infoOpen" v-model:open="infoOpen" :title="title" :url="infoHref" />
    <RecordViewModal v-if="canSee && viewOpen" v-model:open="viewOpen" :title="title" :fields="fields" />
</template>

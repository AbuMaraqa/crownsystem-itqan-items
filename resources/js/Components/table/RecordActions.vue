<script setup lang="ts">
import { Comment, computed, Fragment, ref, Text, useSlots, type VNode } from 'vue';

import { can, t } from '@/lib/i18n';
import { Eye, Info, Pencil, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

import ActionButton from './ActionButton.vue';
import RecordInfoModal from './RecordInfoModal.vue';
import RecordViewModal, { type ViewField } from './RecordViewModal.vue';
import RowMenu from './RowMenu.vue';

/**
 * The row actions of EVERY table, in one order and one look: Info, View, Edit, Delete.
 * Info and View are basic (anyone who can open the list); Edit needs `<Entity> Update` and Delete
 * `<Entity> Delete`, and a row hides them when its state forbids it (`editable`, `deletable`).
 * A table's own actions (print, post, barcode...) go in the default slot as `DropdownItem`s,
 * shown behind a ⋮ button after the four.
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

/** The ⋮ menu shows only when at least one extra action is visible. */
const hasExtra = (): boolean => renders(slots.default?.() ?? []);
</script>

<template>
    <div class="inline-flex items-center gap-1.5">
        <template v-if="canSee">
            <ActionButton :label="t('Info')" tone="info" @click="infoOpen = true"><Info /></ActionButton>
            <ActionButton v-if="viewHref" :label="t('View')" tone="view" :href="viewHref"><Eye /></ActionButton>
            <ActionButton v-else :label="t('View')" tone="view" @click="viewOpen = true"><Eye /></ActionButton>
        </template>
        <template v-if="canEdit">
            <ActionButton v-if="editHref" :label="t('Edit')" tone="edit" :href="editHref"><Pencil /></ActionButton>
            <ActionButton v-else :label="t('Edit')" tone="edit" @click="emit('edit')"><Pencil /></ActionButton>
        </template>
        <ActionButton v-if="canDelete" :label="t('Delete')" tone="delete" @click="emit('delete')"><Trash2 /></ActionButton>
        <RowMenu v-if="hasExtra()"><slot /></RowMenu>
    </div>

    <!-- Rendered only once opened: a table of 100 rows does not mount 200 modals. -->
    <RecordInfoModal v-if="canSee && infoOpen" v-model:open="infoOpen" :title="title" :url="infoHref" />
    <RecordViewModal v-if="canSee && viewOpen" v-model:open="viewOpen" :title="title" :fields="fields" />
</template>

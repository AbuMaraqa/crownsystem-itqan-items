<script setup lang="ts">
import RowMenu from '@/Components/table/RowMenu.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import DropdownSeparator from '@/Components/ui/DropdownSeparator.vue';
import { t } from '@/lib/i18n';
import { Pencil, Trash2 } from '@/lib/icons';

/** Edit and delete of a tab's record, plus the tab's own actions first (`default` slot). */
withDefaults(defineProps<{ deletable?: boolean; editable?: boolean }>(), { deletable: true, editable: true });

const emit = defineEmits<{ edit: []; delete: [] }>();
</script>

<template>
    <RowMenu>
        <slot />
        <DropdownItem v-if="editable" @select="emit('edit')"><Pencil />{{ t('Edit') }}</DropdownItem>
        <template v-if="deletable">
            <DropdownSeparator />
            <DropdownItem destructive @select="emit('delete')"><Trash2 />{{ t('Delete') }}</DropdownItem>
        </template>
    </RowMenu>
</template>

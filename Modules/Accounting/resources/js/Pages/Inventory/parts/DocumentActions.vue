<script setup lang="ts">
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import { can, t } from '@/lib/i18n';
import { Check, CircleCheck, ExternalLink, Printer, Trash2, Undo2 } from '@/lib/icons';
import { route } from '@/lib/route';

import type { DocumentRecord } from './types';

/**
 * The buttons at the top of an inventory document, each behind its permission and the
 * document's state: save the draft, post, delete a draft, cancel a posted document, print, and
 * open its journal entry.
 */
withDefaults(
    defineProps<{
        prefix: string;
        /** The permission entity: `Goods Receipt`, `Delivery Note`, `Stock Transfer`, `Stock Adjustment`. */
        entity: string;
        record: DocumentRecord | null;
        /** The form can be saved (a draft, with its lines loaded). */
        savable: boolean;
        processing?: boolean;
        postLabel: string;
        cancelLabel: string;
        printLabel?: string;
    }>(),
    { processing: false, printLabel: '' },
);

defineEmits<{ save: []; post: []; cancel: []; remove: [] }>();
</script>

<template>
    <Badge v-if="record?.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge>
    <template v-if="savable">
        <Button v-if="can(record ? `${entity} Update` : `${entity} Create`)" :loading="processing" @click="$emit('save')"><Check />{{ t('Save Draft') }}</Button>
        <Button v-if="can(`${entity} Post`)" variant="primary" :loading="processing" @click="$emit('post')"><CircleCheck />{{ postLabel }}</Button>
    </template>
    <Button v-if="record?.is_posted && can(`${entity} Cancel`)" variant="danger" @click="$emit('cancel')"><Undo2 />{{ cancelLabel }}</Button>
    <Button v-if="record" external new-tab :href="route(`accounting.inventory.${prefix}.pdf`, record.id)"><Printer />{{ printLabel || t('Print') }}</Button>
    <Button v-if="record?.journal_entry && can('Journal Entry View')" :href="route('accounting.journal-entries.show', record.journal_entry.id)">
        <ExternalLink /><bdi dir="ltr">{{ record.journal_entry.number }}</bdi>
    </Button>
    <Button v-if="record?.is_draft && can(`${entity} Delete`)" variant="ghost" :aria-label="t('Delete')" @click="$emit('remove')"><Trash2 /></Button>
</template>

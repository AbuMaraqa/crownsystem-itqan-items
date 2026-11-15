<script setup lang="ts">
import { useId } from 'vue';

import Field from '@/Components/form/Field.vue';
import { t } from '@/lib/i18n';
import { ExternalLink, X } from '@/lib/icons';

import type { FileRef } from '../types';

/**
 * A file of a record (stored on the `accounting` disk): the saved one as a link, a new one to
 * upload in its place, or remove it (`remove`).
 */
defineProps<{ label?: string; current?: FileRef | null; error?: string; required?: boolean }>();

const model = defineModel<File | null>();
const remove = defineModel<boolean>('remove', { default: false });
const id = useId();
</script>

<template>
    <Field :id="id" :label="label" :error="error" :required="required">
        <div class="flex flex-wrap items-center gap-2">
            <template v-if="current && !remove && !model">
                <a :href="current.url" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1.5 truncate rounded-lg border border-line px-2.5 py-1.5 text-sm text-brand-600 hover:underline dark:text-brand-400" dir="ltr">
                    <ExternalLink class="size-4 shrink-0" />{{ current.name }}
                </a>
                <button type="button" class="rounded p-1 text-muted hover:text-danger" :aria-label="t('Remove')" :title="t('Remove')" @click="remove = true"><X class="size-4" /></button>
            </template>
            <input
                :id="id"
                type="file"
                class="block w-full text-sm text-muted file:me-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-600 hover:file:bg-brand-100 dark:file:bg-brand-500/10 dark:file:text-brand-400"
                @change="model = ($event.target as HTMLInputElement).files?.[0] ?? null"
            />
        </div>
    </Field>
</template>

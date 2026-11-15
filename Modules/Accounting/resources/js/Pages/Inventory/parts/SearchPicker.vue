<script setup lang="ts" generic="T extends Option">
import { onBeforeUnmount, ref, shallowRef, useId } from 'vue';

import Field from '@/Components/form/Field.vue';
import { controlClasses } from '@/Components/form/controlClasses';
import { t } from '@/lib/i18n';
import { QrCode, Search } from '@/lib/icons';

import { getJson } from './api';
import type { Option } from './types';

/**
 * A search box with its results under it, for the barcode scanner too: Enter takes the first
 * result. `url` answers `?q=term` with `[{ value, label, ... }]`; the box clears after a pick.
 */
const props = withDefaults(
    defineProps<{
        url: string;
        label?: string;
        placeholder?: string;
        scanner?: boolean;
        disabled?: boolean;
        /** Search with an empty box too (the list of open orders). */
        searchEmpty?: boolean;
    }>(),
    { scanner: false, disabled: false, searchEmpty: false },
);

const emit = defineEmits<{ pick: [option: T] }>();

const id = useId();
const term = ref('');
const open = ref(false);
const loading = ref(false);
const results = shallowRef<T[]>([]);
let timer: ReturnType<typeof setTimeout> | undefined;
let request: AbortController | undefined;

function load(): Promise<void> {
    request?.abort();
    request = new AbortController();

    if (term.value.trim() === '' && !props.searchEmpty) {
        results.value = [];

        return Promise.resolve();
    }

    loading.value = true;
    const separator = props.url.includes('?') ? '&' : '?';

    return getJson<T[]>(`${props.url}${separator}q=${encodeURIComponent(term.value.trim())}`, request.signal)
        .then((found) => {
            results.value = found;
        })
        .catch(() => {
            // A newer search replaced this one, or the network failed: the list stays as it is.
        })
        .finally(() => {
            loading.value = false;
        });
}

function onInput(value: string): void {
    term.value = value;
    open.value = true;
    clearTimeout(timer);
    timer = setTimeout(load, 250);
}

function onFocus(): void {
    open.value = true;

    if (props.searchEmpty && !results.value.length) load();
}

function pick(option: T): void {
    emit('pick', option);
    term.value = '';
    results.value = [];
    open.value = false;
}

/** The scanner types the code then Enter: search now and take the first result. */
async function onEnter(): Promise<void> {
    clearTimeout(timer);
    await load();

    if (results.value[0]) pick(results.value[0]);
}

function close(): void {
    setTimeout(() => (open.value = false), 150);
}

onBeforeUnmount(() => {
    clearTimeout(timer);
    request?.abort();
});
</script>

<template>
    <Field :id="id" :label="label">
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-gray-400">
                <QrCode v-if="scanner" class="size-4" />
                <Search v-else class="size-4" />
            </span>
            <input
                :id="id"
                :value="term"
                type="search"
                autocomplete="off"
                :disabled="disabled"
                :placeholder="placeholder"
                :class="[controlClasses, 'h-10 ps-9']"
                @input="onInput(($event.target as HTMLInputElement).value)"
                @focus="onFocus"
                @blur="close"
                @keydown.enter.prevent="onEnter"
                @keydown.escape="open = false"
            />
            <div
                v-if="open && (term.trim() !== '' || searchEmpty)"
                class="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-lg border border-line bg-card py-1 shadow-theme-lg"
            >
                <button
                    v-for="option in results"
                    :key="option.value"
                    type="button"
                    class="flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-sm hover:bg-brand-50 dark:hover:bg-brand-500/10"
                    @mousedown.prevent="pick(option)"
                >
                    <slot name="option" :option="option">{{ option.label }}</slot>
                </button>
                <div v-if="!results.length" class="px-3 py-3 text-sm text-muted">{{ loading ? t('Loading...') : t('No items found') }}</div>
            </div>
        </div>
    </Field>
</template>

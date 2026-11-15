<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

import { t } from '@/lib/i18n';

import type { NodeAction } from './types';

/**
 * The node's right-click menu (also opened by the ⋮ button, the menu key or Shift+F10). The commands
 * come from the server by the node's type and the user's permissions; every command's route checks
 * the permission again. Arrows move, Enter runs, Esc or a click outside closes.
 */
const props = defineProps<{ x: number; y: number; actions: NodeAction[]; loading: boolean }>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ run: [action: NodeAction] }>();

const menu = ref<HTMLElement>();
const position = ref({ top: 0, left: 0 });

function place(): void {
    const width = menu.value?.offsetWidth ?? 200;
    const height = menu.value?.offsetHeight ?? 200;

    position.value = {
        top: Math.max(8, Math.min(props.y, window.innerHeight - height - 8)),
        left: Math.max(8, Math.min(props.x, window.innerWidth - width - 8)),
    };
}

function items(): HTMLButtonElement[] {
    return [...(menu.value?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]') ?? [])];
}

function focusItem(step: number): void {
    const list = items();
    if (!list.length) return;

    const index = list.indexOf(document.activeElement as HTMLButtonElement);
    list[(index + step + list.length) % list.length]?.focus();
}

function close(): void {
    open.value = false;
}

function outside(event: MouseEvent): void {
    if (menu.value && !menu.value.contains(event.target as Node)) close();
}

watch(
    () => [open.value, props.actions, props.loading] as const,
    async ([isOpen]) => {
        document.removeEventListener('mousedown', outside);

        if (!isOpen) return;

        await nextTick();
        place();
        items()[0]?.focus();
        document.addEventListener('mousedown', outside);
    },
);

onBeforeUnmount(() => document.removeEventListener('mousedown', outside));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            ref="menu"
            role="menu"
            :aria-label="t('Actions')"
            class="fixed z-[70] min-w-48 overflow-hidden rounded-xl border border-line bg-card p-1.5 text-sm text-ink shadow-theme-lg"
            :style="{ top: `${position.top}px`, left: `${position.left}px` }"
            @keydown.down.prevent="focusItem(1)"
            @keydown.up.prevent="focusItem(-1)"
            @keydown.esc.prevent="close"
            @keydown.tab.prevent="close"
            @contextmenu.prevent
        >
            <p v-if="loading" class="px-3 py-2 text-muted">{{ t('Loading...') }}</p>
            <p v-else-if="!actions.length" class="px-3 py-2 text-muted">{{ t('No actions available') }}</p>
            <button
                v-for="action in actions"
                v-else
                :key="action.key"
                type="button"
                role="menuitem"
                class="flex w-full items-center rounded-lg px-3 py-2 text-start font-medium text-gray-700 outline-none hover:bg-gray-100 focus:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5 dark:focus:bg-white/5"
                :class="{ 'text-error-600 dark:text-error-400': action.key === 'delete' }"
                @click="
                    close();
                    emit('run', action);
                "
            >
                {{ action.label }}
            </button>
        </div>
    </Teleport>
</template>

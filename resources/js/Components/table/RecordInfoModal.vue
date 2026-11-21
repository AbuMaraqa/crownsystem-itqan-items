<script setup lang="ts">
import { computed, ref, watch, type Component } from 'vue';

import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { Clock, Pencil, Plus, Trash2, Undo2, UserRound } from '@/lib/icons';
import type { UserCellData } from '@/types/user';

import UserCell from './UserCell.vue';

/** One changed field, already readable (`Modules\Core\Support\ActivityLogFormatter::rows()`). */
interface Change {
    field: string;
    label: string;
    old: string;
    new: string;
    changed: boolean;
}

interface Activity {
    id: number;
    event: string;
    description: string;
    causer: UserCellData | null;
    changes: Change[];
    created_at: string | null;
    created_ago: string | null;
}

/** The shape `Modules\Core\Support\RecordInfo::of()` sends. */
interface Info {
    created_by: UserCellData | null;
    created_at: string | null;
    updated_at: string | null;
    activities: Activity[];
}

const props = defineProps<{ title: string; url: string }>();

const open = defineModel<boolean>('open', { required: true });

const info = ref<Info | null>(null);
const failed = ref(false);
const expanded = ref<number[]>([]);

/** Fields shown before «Show all» on a long change. */
const PREVIEW = 4;

watch(
    open,
    async (isOpen) => {
        if (!isOpen || info.value) return;

        failed.value = false;

        try {
            const response = await fetch(props.url, { headers: { Accept: 'application/json' } });

            if (!response.ok) throw new Error(String(response.status));

            info.value = (await response.json()) as Info;
        } catch {
            failed.value = true;
        }
    },
    { immediate: true },
);

interface EventLook {
    icon: Component;
    dot: string;
    badge: string;
}

const looks: Record<string, EventLook> = {
    created: { icon: Plus, dot: 'bg-emerald-500 text-white ring-emerald-100 dark:ring-emerald-500/20', badge: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' },
    updated: { icon: Pencil, dot: 'bg-blue-500 text-white ring-blue-100 dark:ring-blue-500/20', badge: 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400' },
    deleted: { icon: Trash2, dot: 'bg-red-500 text-white ring-red-100 dark:ring-red-500/20', badge: 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' },
    restored: { icon: Undo2, dot: 'bg-amber-500 text-white ring-amber-100 dark:ring-amber-500/20', badge: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' },
};
const otherLook: EventLook = { icon: Clock, dot: 'bg-gray-400 text-white ring-gray-100 dark:ring-white/10', badge: 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300' };

const look = (activity: Activity): EventLook => looks[activity.event] ?? otherLook;

/** A creation lists the first values; any other event lists only what changed. */
const shownChanges = (activity: Activity): Change[] => (activity.event === 'created' ? activity.changes : activity.changes.filter((change) => change.changed));

const visibleChanges = (activity: Activity): Change[] => {
    const changes = shownChanges(activity);

    return expanded.value.includes(activity.id) ? changes : changes.slice(0, PREVIEW);
};

const toggle = (id: number): void => {
    expanded.value = expanded.value.includes(id) ? expanded.value.filter((item) => item !== id) : [...expanded.value, id];
};

const count = computed(() => info.value?.activities.length ?? 0);
</script>

<template>
    <Modal v-model:open="open" :title="`${t('Information')} - ${title}`" width="max-w-3xl">
        <p v-if="failed" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-400" role="alert">{{ t('Could not load this information.') }}</p>

        <div v-else-if="!info" class="space-y-3" aria-busy="true">
            <span class="sr-only">{{ t('Loading...') }}</span>
            <div class="h-20 animate-pulse rounded-xl bg-surface" />
            <div v-for="index in 3" :key="index" class="h-16 animate-pulse rounded-xl bg-surface" />
        </div>

        <template v-else>
            <!-- Who and when -->
            <dl class="grid gap-4 rounded-xl border border-line bg-surface/60 p-4 text-sm sm:grid-cols-3">
                <div class="min-w-0">
                    <dt class="mb-1.5 text-xs font-medium text-muted">{{ t('Created By') }}</dt>
                    <dd>
                        <UserCell v-if="info.created_by" v-bind="info.created_by" />
                        <span v-else class="inline-flex items-center gap-2 text-muted"><UserRound class="size-5" />{{ t('Unknown') }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="mb-1.5 text-xs font-medium text-muted">{{ t('Created At') }}</dt>
                    <dd class="font-medium tabular-nums" dir="ltr">{{ info.created_at ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="mb-1.5 text-xs font-medium text-muted">{{ t('Updated At') }}</dt>
                    <dd class="font-medium tabular-nums" dir="ltr">{{ info.updated_at ?? '-' }}</dd>
                </div>
            </dl>

            <!-- Activity log -->
            <div class="mt-6 mb-3 flex items-center gap-2">
                <h3 class="text-sm font-semibold">{{ t('Activity Log') }}</h3>
                <span class="rounded-full bg-surface px-2 py-0.5 text-xs font-medium text-muted tabular-nums">{{ count }}</span>
            </div>

            <div v-if="!count" class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-line py-10 text-sm text-muted">
                <Clock class="size-6" />
                {{ t('No activities recorded') }}
            </div>

            <ol v-else class="relative max-h-[26rem] space-y-4 overflow-y-auto ps-1 pe-1">
                <li v-for="(activity, index) in info.activities" :key="activity.id" class="relative flex gap-3">
                    <!-- Timeline line + event marker -->
                    <div class="relative flex flex-col items-center">
                        <span class="z-10 flex size-8 shrink-0 items-center justify-center rounded-full ring-4 [&_svg]:size-4" :class="look(activity).dot">
                            <component :is="look(activity).icon" />
                        </span>
                        <span v-if="index < count - 1" class="absolute top-8 -bottom-4 w-px bg-line" aria-hidden="true" />
                    </div>

                    <article class="min-w-0 flex-1 rounded-xl border border-line bg-card p-3.5">
                        <header class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <span class="rounded-md px-2 py-0.5 text-xs font-semibold" :class="look(activity).badge">{{ activity.description }}</span>
                                <span v-if="activity.causer" class="inline-flex min-w-0 items-center gap-1.5 text-sm">
                                    <span class="text-muted">{{ t('By') }}</span>
                                    <img :src="activity.causer.avatar" alt="" width="20" height="20" class="size-5 rounded-full object-cover" />
                                    <span class="truncate font-medium">{{ activity.causer.name }}</span>
                                </span>
                                <span v-else class="text-xs text-muted">{{ t('By the system') }}</span>
                            </div>
                            <time class="shrink-0 text-end text-xs text-muted">
                                <span class="block font-medium text-ink">{{ activity.created_ago }}</span>
                                <span class="block tabular-nums" dir="ltr">{{ activity.created_at }}</span>
                            </time>
                        </header>

                        <ul v-if="shownChanges(activity).length" class="mt-3 divide-y divide-line overflow-hidden rounded-lg border border-line text-sm">
                            <li v-for="change in visibleChanges(activity)" :key="change.field" class="grid gap-1 px-3 py-2 sm:grid-cols-[minmax(7rem,30%)_1fr] sm:items-center sm:gap-3">
                                <span class="font-medium text-muted">{{ change.label }}</span>
                                <span v-if="activity.event === 'created'" class="break-words">{{ change.new }}</span>
                                <span v-else class="flex flex-wrap items-center gap-1.5">
                                    <span class="rounded bg-red-50 px-1.5 py-0.5 break-all text-red-700 line-through decoration-red-300 dark:bg-red-500/10 dark:text-red-400">{{ change.old }}</span>
                                    <span class="text-muted" aria-hidden="true"><span class="rtl:hidden">→</span><span class="ltr:hidden">←</span></span>
                                    <span class="sr-only">{{ t('To') }}</span>
                                    <span class="rounded bg-emerald-50 px-1.5 py-0.5 break-all text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">{{ change.new }}</span>
                                </span>
                            </li>
                        </ul>
                        <button
                            v-if="shownChanges(activity).length > PREVIEW"
                            type="button"
                            class="mt-2 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
                            @click="toggle(activity.id)"
                        >
                            {{ expanded.includes(activity.id) ? t('Show less') : t('Show all (:count)', { count: shownChanges(activity).length }) }}
                        </button>
                    </article>
                </li>
            </ol>
        </template>

        <template #footer>
            <Button @click="open = false">{{ t('Close') }}</Button>
        </template>
    </Modal>
</template>

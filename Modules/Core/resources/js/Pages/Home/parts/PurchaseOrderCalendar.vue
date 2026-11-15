<script setup lang="ts">
import { Calendar, type EventInput } from '@fullcalendar/core';
import arLocale from '@fullcalendar/core/locales/ar';
import dayGridPlugin from '@fullcalendar/daygrid';
import listPlugin from '@fullcalendar/list';
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

import { t } from '@/lib/i18n';

import type { CalendarEvent, PurchaseOrderCalendar } from './types';
import WidgetCard from './WidgetCard.vue';

/**
 * Every date tied to a consolidated purchase order, month by month (DashboardService::purchaseOrderEvents).
 * FullCalendar is heavy, so the page loads this part on its own.
 */
const props = defineProps<{ calendar: PurchaseOrderCalendar }>();

const page = usePage();
const element = ref<HTMLElement>();
const hidden = ref<string[]>([]);
const failed = ref(false);
let instance: Calendar | null = null;

const typeOf = (key: string) => props.calendar.types.find((type) => type.key === key);

function toggle(key: string): void {
    hidden.value = hidden.value.includes(key) ? hidden.value.filter((item) => item !== key) : [...hidden.value, key];
}

async function fetchEvents(info: { startStr: string; endStr: string }): Promise<EventInput[]> {
    const url = new URL(props.calendar.url);
    url.searchParams.set('start', info.startStr);
    url.searchParams.set('end', info.endStr);

    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error(String(response.status));

    failed.value = false;
    const events = ((await response.json()) as { data: CalendarEvent[] }).data;

    return events
        .filter((event) => !hidden.value.includes(event.type))
        .map((event) => {
            const type = typeOf(event.type);

            return {
                id: event.id,
                title: event.title,
                start: event.date,
                allDay: true,
                url: event.url,
                backgroundColor: type?.tint,
                borderColor: type?.color,
                textColor: type?.color,
            };
        });
}

onMounted(() => {
    if (!element.value) return;

    const rtl = page.props.dir === 'rtl';

    instance = new Calendar(element.value, {
        plugins: [dayGridPlugin, listPlugin],
        initialView: 'dayGridMonth',
        locales: [arLocale],
        locale: page.props.locale as string,
        direction: rtl ? 'rtl' : 'ltr',
        firstDay: rtl ? 6 : 0,
        buttonText: { today: t('Today'), month: t('Month'), list: t('List') },
        height: 'auto',
        displayEventTime: false,
        dayMaxEventRows: 4,
        headerToolbar: { start: 'prev,next today', center: 'title', end: 'dayGridMonth,listMonth' },
        events: (info, success, failure) => {
            fetchEvents(info)
                .then(success)
                .catch((error: Error) => {
                    failed.value = true;
                    failure(error);
                });
        },
        // The order opens like any other page (a full load while it is still a Livewire page).
        eventClick: (info) => {
            info.jsEvent.preventDefault();
            if (info.event.url) router.visit(info.event.url);
        },
    });

    instance.render();
});

watch(hidden, () => instance?.refetchEvents());

onBeforeUnmount(() => instance?.destroy());
</script>

<template>
    <WidgetCard :title="t('Consolidated Purchase Orders Calendar')" :description="t('Every date tied to a consolidated purchase order, month by month.')">
        <div class="space-y-4">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="type in calendar.types"
                    :key="type.key"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors"
                    :class="hidden.includes(type.key) ? 'border-dashed border-line text-gray-400 opacity-60' : 'border-line text-gray-700 dark:text-gray-200'"
                    :aria-pressed="!hidden.includes(type.key)"
                    :title="hidden.includes(type.key) ? t('Show') : t('Hide')"
                    @click="toggle(type.key)"
                >
                    <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: hidden.includes(type.key) ? '#cbd5e1' : type.color }" />
                    {{ type.label }}
                </button>
            </div>

            <p v-if="failed" class="text-sm text-danger">{{ t('Could not load the calendar.') }}</p>

            <div ref="element" class="po-calendar text-sm" />
        </div>
    </WidgetCard>
</template>

<style scoped>
/* FullCalendar colors follow the panel tokens, light and dark. */
.po-calendar {
    --fc-border-color: var(--line);
    --fc-page-bg-color: var(--card);
    --fc-neutral-bg-color: var(--surface);
    --fc-list-event-hover-bg-color: var(--surface);
    --fc-today-bg-color: color-mix(in srgb, var(--color-brand-500) 8%, transparent);
    --fc-button-bg-color: var(--card);
    --fc-button-border-color: var(--line);
    --fc-button-text-color: var(--ink);
    --fc-button-hover-bg-color: var(--surface);
    --fc-button-hover-border-color: var(--line);
    --fc-button-active-bg-color: var(--color-brand-500);
    --fc-button-active-border-color: var(--color-brand-500);
}

.po-calendar :deep(.fc-toolbar-title) {
    font-size: 1rem;
    font-weight: 600;
}

.po-calendar :deep(.fc-button) {
    border-radius: var(--radius-control);
    font-size: 0.8125rem;
    box-shadow: none;
}

.po-calendar :deep(.fc-button-active) {
    color: #fff;
}
</style>

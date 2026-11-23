<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import DropdownSeparator from '@/Components/ui/DropdownSeparator.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { Bell, CheckRead, EnvelopeUnread, Eye, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

defineOptions({ layout: AdminLayout });

/** NotificationController::row. Only the signed-in user's own notifications. */
interface Row {
    id: string;
    title: string | null;
    message: string;
    url: string | null;
    time: string | null;
    date: string | null;
    read: boolean;
    read_at: string | null;
    resolved: boolean;
    sender: string;
}

defineProps<{ table: TablePayload<Row>; unread: number }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'title', label: t('Notification') },
    { key: 'sender', label: t('From'), toggleable: true },
    { key: 'date', label: t('Date'), sort: 'created_at' },
    { key: 'read_at', label: t('Read at'), toggleable: true, hidden: true },
]);

const filters = computed<TableFilter[]>(() => [{ type: 'boolean', key: 'read', label: t('Status'), trueLabel: t('Read'), falseLabel: t('Unread') }]);

const keep = { preserveScroll: true, preserveState: true } as const;

/** The bell's count follows every change. */
const reload = { ...keep, only: ['table', 'unread', 'unreadNotifications', 'flash'] };

function open(row: Row): void {
    window.location.href = route('notifications.open', row.id);
}

function toggle(row: Row): void {
    router.post(route('notifications.toggle', row.id), {}, reload);
}

async function remove(row: Row): Promise<void> {
    if (await confirm({ title: t('Delete this notification?'), description: t('This cannot be undone.') })) {
        router.delete(route('notifications.destroy', row.id), reload);
    }
}

async function bulk(action: 'read' | 'unread' | 'delete', ids: (number | string)[]): Promise<void> {
    if (action === 'delete' && !(await confirm({ title: t('Delete :count items?', { count: ids.length }), description: t('This cannot be undone.') }))) return;

    router.post(route('notifications.bulk'), { action, ids }, reload);
}

function readAll(): void {
    router.post(route('notifications.read'), {}, reload);
}
</script>

<template>
    <PageHeader :title="t('Notifications')" :count="table.meta.total">
        <template #actions>
            <Button :disabled="unread === 0" @click="readAll"><CheckRead />{{ t('Mark all as read') }}</Button>
        </template>
    </PageHeader>

    <DataTable
        :table="table"
        :columns="columns"
        :filters="filters"
        selectable
        :only="['table', 'unread', 'flash']"
        :empty-title="t('No notifications')"
        :empty-text="t('Documents and messages sent to you will appear here.')"
    >
        <template #cell-title="{ row }">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg" :class="row.read ? 'bg-surface text-gray-400' : 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'" :title="row.read ? t('Read') : t('Unread')">
                    <CheckRead v-if="row.read" class="size-4" />
                    <Bell v-else class="size-4" />
                </span>
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2" :class="row.read ? 'text-gray-700 dark:text-gray-300' : 'font-semibold text-gray-900 dark:text-white'">
                        {{ row.title }}
                        <Badge v-if="row.resolved" tone="success">{{ t('Resolved') }}</Badge>
                    </p>
                    <p class="mt-0.5 text-sm text-muted">{{ row.message }}</p>
                </div>
            </div>
        </template>
        <template #cell-date="{ row }">
            <span :title="row.date ?? undefined">{{ row.time }}</span>
        </template>
        <template #cell-read_at="{ row }">
            <span class="tabular">{{ row.read_at ?? '—' }}</span>
        </template>
        <template #actions="{ row }">
            <!-- Not RecordActions: a notification is the user's own message (uuid, no Update/Delete permission, no edit, no activity log). Same ⋮ menu. -->
            <RowMenu>
                <DropdownItem v-if="row.url" @select="open(row)"><Eye />{{ t('View') }}</DropdownItem>
                <DropdownItem v-if="row.read" @select="toggle(row)"><EnvelopeUnread />{{ t('Mark as unread') }}</DropdownItem>
                <DropdownItem v-else @select="toggle(row)"><CheckRead />{{ t('Mark as read') }}</DropdownItem>
                <DropdownSeparator />
                <DropdownItem destructive @select="remove(row)"><Trash2 />{{ t('Delete') }}</DropdownItem>
            </RowMenu>
        </template>
        <template #bulk="{ selected }">
            <Button size="sm" @click="bulk('read', selected)">{{ t('Mark as read') }}</Button>
            <Button size="sm" @click="bulk('unread', selected)">{{ t('Mark as unread') }}</Button>
            <Button variant="danger" size="sm" @click="bulk('delete', selected)">{{ t('Delete') }}</Button>
        </template>
    </DataTable>
</template>

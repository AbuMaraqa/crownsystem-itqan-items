{{-- أدوات الإشعارات الحية في رأس قائمة الجرس: حالة الاتصال، وتشغيل/كتم الصوت، وإشعارات سطح المكتب. --}}
<div x-data class="contents">
<template x-if="$store.notifications?.enabled">
<div class="flex items-center gap-1">
    <span
        class="flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium"
        :class="$store.notifications.connected
            ? 'bg-success/10 text-success'
            : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400'"
        :title="$store.notifications.connected ? @js(__('Live notifications are connected')) : @js(__('Reconnecting to live notifications…'))"
    >
        <span class="relative flex h-2 w-2">
            <span x-show="$store.notifications.connected" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success opacity-60"></span>
            <span class="relative inline-flex h-2 w-2 rounded-full" :class="$store.notifications.connected ? 'bg-success' : 'bg-gray-400'"></span>
        </span>
        <span x-text="$store.notifications.connected ? @js(__('Live')) : @js(__('Offline'))"></span>
    </span>

    <button
        type="button"
        class="rounded-md p-1.5 text-gray-500 transition hover:bg-primary/10 hover:text-primary dark:text-gray-400"
        @click.stop="$store.notifications.toggleSound()"
        :title="$store.notifications.soundEnabled ? @js(__('Mute notification sound')) : @js(__('Enable notification sound'))"
        :aria-pressed="$store.notifications.soundEnabled.toString()"
    >
        <span x-show="$store.notifications.soundEnabled">@svg('heroicon-o-speaker-wave', 'h-4 w-4')</span>
        <span x-show="! $store.notifications.soundEnabled">@svg('heroicon-o-speaker-x-mark', 'h-4 w-4')</span>
    </button>

    <button
        type="button"
        x-show="$store.notifications.desktopSupported && $store.notifications.desktopPermission === 'default'"
        class="rounded-md p-1.5 text-gray-500 transition hover:bg-primary/10 hover:text-primary dark:text-gray-400"
        @click.stop="$store.notifications.requestDesktop()"
        title="{{ __('Enable desktop notifications') }}"
    >
        @svg('heroicon-o-computer-desktop', 'h-4 w-4')
    </button>
</div>
</template>
</div>

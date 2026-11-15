<div>
    <style>
        @keyframes crown-toast-in {
            from { opacity: 0; transform: translateY(-12px) scale(.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes crown-toast-out {
            to { opacity: 0; transform: translateY(-8px) scale(.97); }
        }
        .crown-toast { animation: crown-toast-in .32s cubic-bezier(.21, 1.02, .73, 1) both; }
        .crown-toast.is-leaving { animation: crown-toast-out .22s ease-in both; }
        @media (prefers-reduced-motion: reduce) {
            .crown-toast, .crown-toast.is-leaving { animation: none; }
        }
    </style>

    <div
        wire:ignore
        x-data
        x-init="$store.notifications?.setUnread({{ $unreadCount }})"
        class="pointer-events-none fixed top-[76px] z-[70] flex w-[min(380px,calc(100vw-2rem))] flex-col gap-3 end-4"
        aria-live="polite"
        role="region"
        aria-label="{{ __('Live notifications') }}"
    >
        <template x-for="toast in ($store.notifications?.toasts ?? [])" :key="toast.id">
            <div
                class="crown-toast pointer-events-auto relative overflow-hidden rounded-xl border border-gray-100 bg-white shadow-[0_12px_32px_-8px_rgba(15,23,42,.25)] dark:border-white/10 dark:bg-[#1b2e4b]"
                :class="{ 'is-leaving': toast.leaving, 'cursor-pointer': toast.url }"
                role="status"
                @mouseenter="$store.notifications.pause(toast, true)"
                @mouseleave="$store.notifications.pause(toast, false)"
                @click="$store.notifications.open(toast)"
            >
                <span
                    class="absolute inset-y-0 start-0 w-1"
                    :class="{
                        'bg-primary': toast.level === 'info',
                        'bg-success': toast.level === 'success',
                        'bg-warning': toast.level === 'warning',
                        'bg-danger': toast.level === 'danger',
                    }"
                ></span>

                <div class="flex items-start gap-3 p-4 ps-5">
                    <span
                        class="grid h-10 w-10 shrink-0 place-content-center rounded-full"
                        :class="{
                            'bg-primary/10 text-primary': toast.level === 'info',
                            'bg-success/10 text-success': toast.level === 'success',
                            'bg-warning/10 text-warning': toast.level === 'warning',
                            'bg-danger/10 text-danger': toast.level === 'danger',
                        }"
                    >
                        <span x-show="toast.level === 'info'">@svg('heroicon-o-bell-alert', 'h-5 w-5')</span>
                        <span x-show="toast.level === 'success'">@svg('heroicon-o-check-circle', 'h-5 w-5')</span>
                        <span x-show="toast.level === 'warning'">@svg('heroicon-o-exclamation-triangle', 'h-5 w-5')</span>
                        <span x-show="toast.level === 'danger'">@svg('heroicon-o-exclamation-circle', 'h-5 w-5')</span>
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold leading-5 text-gray-900 dark:text-white" x-text="toast.title"></p>
                        <p
                            x-show="toast.body"
                            class="mt-1 line-clamp-2 text-[13px] leading-5 text-gray-600 dark:text-gray-300"
                            x-text="toast.body"
                        ></p>
                        <p class="mt-1.5 flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500">
                            <span x-show="toast.sender" x-text="toast.sender"></span>
                            <span x-show="toast.sender" aria-hidden="true">&middot;</span>
                            <span>{{ __('Just now') }}</span>
                        </p>
                    </div>

                    <button
                        type="button"
                        class="-m-1 rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-white"
                        @click.stop="$store.notifications.dismiss(toast.id)"
                        title="{{ __('Close') }}"
                    >
                        @svg('heroicon-o-x-mark', 'h-4 w-4')
                    </button>
                </div>

                <span
                    class="absolute bottom-0 start-0 h-0.5"
                    :class="{
                        'bg-primary/60': toast.level === 'info',
                        'bg-success/60': toast.level === 'success',
                        'bg-warning/60': toast.level === 'warning',
                        'bg-danger/60': toast.level === 'danger',
                    }"
                    :style="`width: ${toast.progress}%`"
                ></span>
            </div>
        </template>
    </div>
</div>

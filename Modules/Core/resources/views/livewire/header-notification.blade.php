<div class="dropdown" x-data="dropdown" @click.outside="open = false" wire:poll.60s.visible>
    {{-- يبقي عدد غير المقروء في عنوان التبويب متزامناً (يستخدمه مستمع الاشعارات الحية) --}}
    <span hidden wire:key="unread-{{ $unreadCount }}" x-init="$store.notifications?.setUnread({{ $unreadCount }})"></span>

    <a href="javascript:;"
       class="relative block rounded-full bg-white-light/40 p-2 hover:bg-white-light/90 hover:text-primary dark:bg-dark/40 dark:hover:bg-dark/60"
       title="{{ __('Notifications') }}"
       @click="toggle">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M19.0001 9.7041V9C19.0001 5.13401 15.8661 2 12.0001 2C8.13407 2 5.00006 5.13401 5.00006 9V9.7041C5.00006 10.5491 4.74995 11.3752 4.28123 12.0783L3.13263 13.8012C2.08349 15.3749 2.88442 17.5139 4.70913 18.0116C9.48258 19.3134 14.5175 19.3134 19.291 18.0116C21.1157 17.5139 21.9166 15.3749 20.8675 13.8012L19.7189 12.0783C19.2502 11.3752 19.0001 10.5491 19.0001 9.7041Z"
                stroke="currentColor" stroke-width="1.5" />
            <path d="M7.5 19C8.15503 20.7478 9.92246 22 12 22C14.0775 22 15.845 20.7478 16.5 19"
                  stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            <path d="M12 6V10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
        </svg>

        @if ($unreadCount > 0)
            <span class="absolute -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold leading-none text-white -end-1">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </a>

    <ul x-cloak x-show="open" x-transition x-transition.duration.300ms
        class="top-11 w-[300px] divide-y !py-0 text-dark end-[-0.5rem] dark:divide-white/10 dark:text-white-dark sm:w-[370px]">
        <li>
            <div class="flex items-center justify-between gap-2 px-4 py-2 font-semibold hover:!bg-transparent">
                <h4 class="text-lg">{{ __('Notifications') }}</h4>
                <div class="flex items-center gap-2">
                    @if (view()->exists('core::components.notifications.sound-toggle'))
                        <x-core::notifications.sound-toggle />
                    @endif
                    @if ($unreadCount > 0)
                        <span class="badge bg-primary/80">{{ __(':count unread', ['count' => $unreadCount]) }}</span>
                    @endif
                </div>
            </div>
        </li>

        <div class="max-h-[380px] overflow-y-auto">
            @forelse ($notifications as $notification)
                <li wire:key="bell-{{ $notification['id'] }}"
                    @class([
                        'border-b border-[#e0e6ed] dark:border-[#1b2e4b] dark:text-white-light/90',
                        'bg-primary/5 dark:bg-primary/10' => ! $notification['is_read'],
                    ])>
                    <div class="group flex items-start gap-3 px-4 py-3">
                        <button type="button" class="flex flex-1 items-start gap-3 text-start"
                                wire:click="open('{{ $notification['id'] }}')">
                            <span class="grid h-10 w-10 shrink-0 place-content-center rounded-full {{ $notification['tone']->badgeClasses() }}">
                                @svg($notification['icon_svg'], 'h-5 w-5')
                            </span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block truncate', 'font-bold' => ! $notification['is_read'], 'font-semibold' => $notification['is_read']])>
                                    {{ $notification['title'] }}
                                </span>
                                @if ($notification['message'])
                                    <span class="mt-0.5 block text-xs text-gray-600 line-clamp-2 dark:text-gray-400">{{ $notification['message'] }}</span>
                                @endif
                                <span class="mt-1 flex items-center gap-2 text-[11px] text-gray-500">
                                    {{ $notification['created_at']?->diffForHumans() }}
                                    @if ($notification['resolved_at'] ?? null)
                                        <span class="inline-flex items-center gap-0.5 rounded bg-green-100 px-1.5 font-semibold text-green-700 dark:bg-green-500/15 dark:text-green-300">@svg('solar-check-circle-bold-duotone', 'h-3 w-3'){{ __('Resolved') }}</span>
                                    @endif
                                </span>
                            </span>
                        </button>

                        <div class="flex shrink-0 flex-col items-center gap-1">
                            <button type="button"
                                    class="text-neutral-400 hover:text-primary"
                                    title="{{ $notification['is_read'] ? __('Mark as unread') : __('Mark as read') }}"
                                    wire:loading.attr="disabled" wire:click="toggleRead('{{ $notification['id'] }}')">
                                @svg($notification['is_read'] ? 'solar-unread-bold-duotone' : 'solar-check-read-bold-duotone', 'h-5 w-5')
                            </button>
                            <button type="button"
                                    class="text-neutral-300 opacity-0 hover:text-danger group-hover:opacity-100"
                                    title="{{ __('Delete') }}"
                                    wire:loading.attr="disabled" wire:click="remove('{{ $notification['id'] }}')">
                                @svg('solar-trash-bin-minimalistic-bold-duotone', 'h-4 w-4')
                            </button>
                        </div>
                    </div>
                </li>
            @empty
                <li class="p-6 text-center text-gray-500">{{ __('No notifications') }}</li>
            @endforelse
        </div>

        <li>
            <div class="flex items-center justify-between px-4 py-2 text-sm">
                <button type="button" class="font-semibold text-primary disabled:opacity-40"
                        wire:loading.attr="disabled" wire:click="markAllAsRead" @disabled($unreadCount === 0)>
                    {{ __('Mark all as read') }}
                </button>
                @can('Notification View List')
                <a href="{{ route('notifications.index') }}" class="font-semibold text-primary hover:underline">
                    {{ __('View all') }}
                </a>
                @endcan
            </div>
        </li>
    </ul>
</div>

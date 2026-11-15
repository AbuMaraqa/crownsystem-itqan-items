{{-- عرض المستخدم الموحَّد؛ يُستدعى عبر $user->user_display_html فقط. أي تعديل هنا يظهر في كل النظام. --}}
@php($subtitle = $user->email ?: $user->phone)
<div class="flex items-center gap-3">
    <img src="{{ $user->avatar_url }}" alt="{{ $user->name }}" loading="lazy"
        class="h-10 w-10 shrink-0 rounded-full border border-gray-300 object-cover dark:border-gray-600">
    <div class="flex min-w-0 flex-col">
        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</span>
        @if ($subtitle)
            <span class="truncate text-sm text-gray-500 dark:text-gray-400"><bdi>{{ $subtitle }}</bdi></span>
        @endif
    </div>
</div>

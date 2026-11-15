@props(['color' => 'primary', 'icon' => null, 'href' => null])

@php
    $colors = [
        'primary' => 'bg-primary text-white hover:opacity-90',
        'success' => 'bg-green-600 text-white hover:bg-green-700',
        'info' => 'bg-sky-600 text-white hover:bg-sky-700',
        'warning' => 'bg-amber-500 text-white hover:bg-amber-600',
        'danger' => 'bg-red-600 text-white hover:bg-red-700',
        'gray' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10',
    ];
    // حلقة تركيز ظاهرة للتنقل بلوحة المفاتيح (Tab)، ومؤشر يد، وزر معطَّل اثناء الطلب.
    $classes = 'inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-offset-gray-900 '.($colors[$color] ?? $colors['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon) @svg($icon, 'h-4 w-4') @endif
        {{ $slot }}
    </a>
@else
    <button type="button" wire:loading.attr="disabled" {{ $attributes->class($classes) }}>
        @if ($icon) @svg($icon, 'h-4 w-4') @endif
        {{ $slot }}
    </button>
@endif

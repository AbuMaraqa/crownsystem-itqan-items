@props(['status', 'label' => null])

@php
    $styles = [
        'gray' => 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10',
        'info' => 'bg-sky-100 text-sky-700 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-500/20',
        'warning' => 'bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/20',
        'success' => 'bg-green-100 text-green-700 ring-green-200 dark:bg-green-500/15 dark:text-green-300 dark:ring-green-500/20',
        'danger' => 'bg-red-100 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/20',
        'dark' => 'bg-gray-900 text-white ring-gray-900 dark:bg-black dark:text-white dark:ring-white/20',
    ];
    $color = is_object($status) && method_exists($status, 'getColor') ? $status->getColor() : (is_string($status) ? $status : 'gray');
    $text = $label ?? (is_object($status) && method_exists($status, 'getLabel') ? $status->getLabel() : '');
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-inset', $styles[$color] ?? $styles['gray']]) }}>
    {{ $text }}
</span>

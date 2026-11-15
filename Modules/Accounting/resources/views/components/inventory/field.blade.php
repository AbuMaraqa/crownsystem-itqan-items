@props(['label', 'required' => false, 'hint' => null])

<label {{ $attributes->class(['block']) }}>
    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
        {{ $label }}
        @if ($required)
            <span class="text-red-500">*</span>
        @endif
    </span>
    {{ $slot }}
    @if ($hint)
        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
    @endif
</label>

@props(['options' => [], 'placeholder' => null, 'disabled' => false])

<select
    {{ $disabled ? 'disabled' : '' }}
    {{ $attributes->class([
        'block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition focus:border-primary focus:ring-1 focus:ring-primary disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100',
    ]) }}
>
    @if ($placeholder !== null)
        <option value="">{{ $placeholder }}</option>
    @endif
    @foreach ($options as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</select>

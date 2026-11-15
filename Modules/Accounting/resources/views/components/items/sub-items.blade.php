{{--
    لوحة الاصناف الفرعية تحت الصنف الرئيسي، مفتوحة دائماً كما في شبكة الاصناف:
    رأس «الاصناف الفرعية (n)» ثم جدول مضغوط <x-accounting::items.sub-table> في الفتحة الافتراضية.
    colspan: داخل جدول (صف tr يمتد على كل الاعمدة)؛ بدونه تُعرض div مستقلة (بطاقة او عمود Filament).
    <x-slot:actions> ازرار يمين الرأس (اختياري).
--}}
@props(['colspan' => null, 'count' => null, 'title' => null])

@php
    $panelClass = 'border-s-2 border-primary/40 bg-primary/[0.03] px-3 py-3 dark:bg-white/5';
@endphp

@if ($colspan)
    <tr {{ $attributes }}>
        <td colspan="{{ $colspan }}" class="p-0">
            <div class="{{ $panelClass }}">
@else
    <div {{ $attributes->class([$panelClass]) }}>
@endif
                <div class="mb-2 flex items-center justify-between gap-3">
                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                        {{ $title ?? __('Sub Items') }}
                        @if ($count !== null)
                            <span class="text-gray-400">({{ $count }})</span>
                        @endif
                    </span>

                    @isset($actions)
                        <div class="flex items-center gap-2">{{ $actions }}</div>
                    @endisset
                </div>

                {{ $slot }}
@if ($colspan)
            </div>
        </td>
    </tr>
@else
    </div>
@endif

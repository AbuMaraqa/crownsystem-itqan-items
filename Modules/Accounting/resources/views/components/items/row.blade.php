{{--
    صف صنف في <x-accounting::items.table> كصف الشبكة الرئيسي: # | الصنف | باركود ثم خلايا المستند (الفتحة الافتراضية).
    main: صنف رئيسي (صنف وزن) تتبعه لوحة اصنافه الفرعية <x-accounting::items.sub-items>.
    <x-slot:details> سطر صغير تحت اسم الصنف (الطول، المواقع...).
--}}
@props(['number' => null, 'label' => null, 'barcode' => null, 'main' => false])

<tr {{ $attributes->class(['border-t border-gray-200 hover:bg-gray-50/60 dark:border-white/10 dark:hover:bg-white/5']) }}>
    <td class="w-10 px-2 py-2 text-center text-xs text-gray-400">{{ $number }}</td>
    <td class="px-2 py-2">
        <div class="flex items-center gap-2">
            @if ($main)
                @svg('heroicon-m-rectangle-stack', 'h-4 w-4 shrink-0 text-primary')
            @endif
            <span @class([
                'text-sm text-gray-900 dark:text-gray-100',
                'font-semibold' => $main,
                'font-medium' => ! $main,
            ])>{{ filled($label) ? $label : '—' }}</span>
        </div>
        {{ $details ?? '' }}
    </td>
    <td class="px-2 py-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ filled($barcode) ? $barcode : ($main ? '' : '—') }}</td>
    {{ $slot }}
</tr>

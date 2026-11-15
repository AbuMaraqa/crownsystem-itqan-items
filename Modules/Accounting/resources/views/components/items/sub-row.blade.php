{{--
    صف صنف فرعي في <x-accounting::items.sub-table>: رقم بشكل 1.1 | الصنف | باركود ثم خلايا المستند (الفتحة الافتراضية).
    highlight: صف مطابق للبحث. <x-slot:lead> خلية قبل الرقم، <x-slot:details> سطر تحت اسم الصنف.
--}}
@props(['number' => null, 'label' => null, 'barcode' => null, 'highlight' => false])

<tr {{ $attributes->class([
    'border-t border-gray-200 dark:border-white/10',
    'bg-amber-50 font-semibold text-gray-900 dark:bg-amber-500/10 dark:text-gray-100' => $highlight,
    'text-gray-700 dark:text-gray-300' => ! $highlight,
]) }}>
    {{ $lead ?? '' }}
    <td class="px-2 py-1.5 text-center font-mono text-gray-400">{{ $number }}</td>
    <td class="px-2 py-1.5">
        <div>{{ filled($label) ? $label : '—' }}</div>
        {{ $details ?? '' }}
    </td>
    <td class="px-2 py-1.5 font-mono text-gray-500 dark:text-gray-400">{{ filled($barcode) ? $barcode : '—' }}</td>
    {{ $slot }}
</tr>

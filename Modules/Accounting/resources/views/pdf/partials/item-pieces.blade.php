{{-- الاصناف الفرعية (قطع) تحت سطر صنف الوزن في كل مستندات البنود.
     $item: سطر بعلاقة pieces.product، $number: رقم السطر، $columns: عدد اعمدة جدول البنود،
     $barcodeColumn: للجدول عمود باركود مستقل (والا يُكتب الباركود مع اسم الصنف). --}}
@php
    $barcodeColumn = $barcodeColumn ?? true;
    $detailsSpan = max($columns - ($barcodeColumn ? 4 : 3), 1);
@endphp

@foreach ($item->pieces as $piece)
    <tr class="piece-row">
        <td>{{ $number }}.{{ $loop->iteration }}</td>
        <td>
            @if ($piece->product?->image)
                <img class="piece-thumb" src="{{ $piece->product->image }}" width="30" height="30" style="height: 8mm; width: 8mm;" alt="">
            @endif
            {{ $piece->description }}
            @if (! $barcodeColumn && $piece->barcode)
                ({{ $piece->barcode }})
            @endif
        </td>
        @if ($barcodeColumn)
            <td>{{ $piece->barcode }}</td>
        @endif
        <td class="text-end" colspan="{{ $detailsSpan }}">
            {{ __('Piece Count') }}: {{ number_format((float) $piece->piece_count, 2) }} &nbsp;
            {{ __('Piece Length') }}: {{ number_format((float) $piece->piece_length, 2) }} &nbsp;
            {{ __('Weight per Meter') }}: {{ number_format((float) $piece->weight_per_meter, 3) }}
        </td>
        <td class="text-end">{{ __('Total Weight') }}: {{ number_format((float) $piece->total_weight, 3) }}</td>
    </tr>
@endforeach

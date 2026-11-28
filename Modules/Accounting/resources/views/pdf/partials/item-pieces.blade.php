{{-- الاصناف الفرعية (قطع) تحت سطر صنف الوزن في كل مستندات البنود: صف عنوان رمادي ثم صف لكل قطعة.
     $item: سطر بعلاقة pieces.product، $number: رقم السطر، $columns: عدد اعمدة جدول البنود،
     $barcodeColumn: للقطع عمود باركود مستقل (والا يُكتب الباركود مع اسم الصنف).
     الاعمدة: # | صورة | باركود | اسم الصنف | الطول | وزن المتر | عدد القطع | الوزن؛
     في الجدول الاضيق تنضم الصورة ثم الباركود الى خانة الاسم، وتحت 6 اعمدة تُجمع التفاصيل في خانة واحدة. --}}
@php
    $compact = $columns < 6;
    $showBarcode = ! $compact && ($barcodeColumn ?? true) && $columns >= 7;
    $base = 6 + ($showBarcode ? 1 : 0);
    $showImage = ! $compact && $columns > $base;
    $nameSpan = $compact ? 1 : $columns - $base - ($showImage ? 1 : 0) + 1;
    $detailsSpan = max($columns - 3, 1);
    $fmt = fn ($value, $decimals) => \Modules\Accounting\Support\Number::clean((float) $value, $decimals);
@endphp

@if ($item->pieces->isNotEmpty())
    <tr class="piece-head">
        <td>#</td>
        @if ($showImage)
            <td></td>
        @endif
        @if ($showBarcode)
            <td>{{ __('Barcode') }}</td>
        @endif
        <td colspan="{{ $nameSpan }}">{{ __('Item Name') }}</td>
        @if ($compact)
            <td colspan="{{ $detailsSpan }}">{{ __('Length') }} / {{ __('Weight per Meter') }} / {{ __('Piece Count') }}</td>
        @else
            <td>{{ __('Length') }}</td>
            <td>{{ __('Weight per Meter') }}</td>
            <td>{{ __('Piece Count') }}</td>
        @endif
        <td>{{ __('Weight') }}</td>
    </tr>
@endif

@foreach ($item->pieces as $piece)
    @php
        $image = $piece->product?->image;
        $thumb = $image ? '<img class="piece-thumb" src="'.e($image).'" style="height: 9mm; max-width: 22mm;" alt="">' : '';
    @endphp
    <tr class="piece-row">
        <td>{{ $number }}.{{ $loop->iteration }}</td>
        @if ($showImage)
            <td>{!! $thumb !!}</td>
        @endif
        @if ($showBarcode)
            <td class="ltr">{{ $piece->barcode }}</td>
        @endif
        <td class="start" colspan="{{ $nameSpan }}">
            @unless ($showImage)
                {!! $thumb !!}
            @endunless
            {{ $piece->description }}
            @if (! $showBarcode && $piece->barcode)
                <span class="ltr">({{ $piece->barcode }})</span>
            @endif
        </td>
        @if ($compact)
            <td colspan="{{ $detailsSpan }}">{{ $fmt($piece->piece_length, 3) }} / {{ $fmt($piece->weight_per_meter, 3) }} / {{ $fmt($piece->piece_count, 2) }}</td>
        @else
            <td>{{ $fmt($piece->piece_length, 3) }}</td>
            <td>{{ $fmt($piece->weight_per_meter, 3) }}</td>
            <td>{{ $fmt($piece->piece_count, 2) }}</td>
        @endif
        <td>{{ $fmt($piece->total_weight, 3) }}</td>
    </tr>
@endforeach

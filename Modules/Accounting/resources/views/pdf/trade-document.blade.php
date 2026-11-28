{{-- فاتورة مشتريات / مردود مبيعات / مردود مشتريات / عروض الاسعار: بيانات، بنود، مجاميع.
     يمرر AccountingPdfService: $document، $docTitle، $party، $meta، $discountColumn، $discountLabel. --}}
@php
    $money = fn ($value) => number_format((float) $value, 2);
    $quantity = fn ($value) => \Modules\Accounting\Support\Number::quantity($value);
    $withPieces = $document->items->first()?->relationLoaded('pieces') ?? false;

    $totals = [
        ...\Modules\Accounting\Support\PdfTotals::weights($document->items),
        ...\Modules\Accounting\Support\PdfTotals::money($document, $discountColumn, $discountLabel, __('Total')),
    ];
    $notes = $document->notes;
    $signatures = [__('Prepared By'), __('Approved By')];
@endphp

@extends('core::pdf.document')

@section('content')
    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th>{{ __('Item Name') }}</th>
                <th style="width: 12%;">{{ __('Quantity') }}</th>
                <th style="width: 12%;">{{ __('Unit Price') }}</th>
                <th style="width: 11%;">{{ __('Discount') }}</th>
                <th style="width: 13%;">{{ __('Total Price') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->items as $index => $item)
                <tr class="main-row">
                    <td>{{ $index + 1 }}</td>
                    <td class="start">
                        {{ $item->description }}
                        @if ($item->barcode)
                            <span class="ltr">({{ $item->barcode }})</span>
                        @endif
                    </td>
                    <td>{{ $quantity($item->quantity) }}</td>
                    <td>{{ $money($item->unit_price) }}</td>
                    <td>{{ $money($item->discount_amount) }}</td>
                    <td>{{ $money($item->line_total) }}</td>
                </tr>

                @if ($withPieces)
                    @include('accounting::pdf.partials.item-pieces', ['item' => $item, 'number' => $index + 1, 'columns' => 6, 'barcodeColumn' => false])
                @endif
            @endforeach
        </tbody>
    </table>
@endsection

{{-- فاتورة مبيعات: البنود باصنافها الفرعية، ثم الخدمات ومواد العميل والمجاميع. --}}
@php
    $money = fn ($value) => number_format((float) $value, 2);
    $quantity = fn ($value) => \Modules\Accounting\Support\Number::quantity($value);
    $lines = $invoice->items->reject->isService()->values();

    $docTitle = __('Sales Invoice');
    $party = ['name' => $invoice->client?->name];
    $meta = [
        [__('Invoice Date'), $invoice->invoice_date?->format('d/m/Y')],
        [__('Due Date'), $invoice->due_date?->format('d/m/Y')],
        [__('Invoice No'), $invoice->reference_no],
        [__('Sales Order'), $invoice->salesOrder?->reference_no],
        [__('Currency'), $invoice->currency?->code],
        [__('Tax'), $invoice->tax?->name ?? __('No tax')],
    ];
    $totals = [
        ...\Modules\Accounting\Support\PdfTotals::weights($lines),
        ...\Modules\Accounting\Support\PdfTotals::money($invoice, 'invoice_discount', __('Invoice Discount'), __('Balance Due')),
    ];
    $notes = $invoice->notes;
    $signatures = [__('Customer Signature'), __('Employee Signature')];
@endphp

@extends('core::pdf.document')

@section('content')
    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th style="width: 13%;">{{ __('Barcode') }}</th>
                <th style="width: 10%;">{{ __('Quantity') }}</th>
                <th>{{ __('Item Name') }}</th>
                <th style="width: 10%;">{{ __('Unit Price') }}</th>
                <th style="width: 10%;">{{ __('Discount') }}</th>
                <th style="width: 11%;">{{ __('Total Price') }}</th>
                <th style="width: 10%;">{{ __('Actual Weight') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $item)
                @php $weighed = $item->isWeightPricedLine(); @endphp
                <tr class="main-row">
                    <td>{{ $loop->iteration }}</td>
                    <td class="ltr">{{ $item->barcode }}</td>
                    <td>{{ $quantity($item->quantity) }}</td>
                    <td class="start">{{ $item->description }}</td>
                    <td>{{ $money($item->unit_price) }}</td>
                    <td>{{ $money($item->discount_amount) }}</td>
                    <td>{{ $money($item->line_total) }}</td>
                    <td>{{ $weighed ? $quantity($item->actual_weight) : '-' }}</td>
                </tr>

                @include('accounting::pdf.partials.item-pieces', ['item' => $item, 'number' => $loop->iteration, 'columns' => 8])
            @endforeach
        </tbody>
    </table>

    @include('accounting::pdf.partials.sales-services', ['lines' => $invoice->items->filter->isService()])
    @include('accounting::pdf.partials.customer-materials', ['materials' => $invoice->salesOrder?->customerMaterials ?? collect()])
@endsection

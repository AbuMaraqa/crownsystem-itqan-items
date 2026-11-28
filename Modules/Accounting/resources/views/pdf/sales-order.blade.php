{{-- طلبية بيع: سطر لكل صنف ثم اصنافه الفرعية بصف عنوان رمادي، وبعدها الخدمات ومواد العميل والمجاميع. --}}
@php
    $money = fn ($value) => number_format((float) $value, 2);
    $weight = fn ($value) => \Modules\Accounting\Support\Number::quantity($value);
    $lines = $order->items->reject->isService()->values();

    $docTitle = __('Sales Order');
    $party = ['name' => $order->client?->name];
    $meta = [
        [__('Order Date'), $order->order_date?->format('d/m/Y')],
        [__('Expected Delivery Date'), $order->expected_delivery_date?->format('d/m/Y')],
        [__('Order No'), $order->reference_no],
        [__('Warehouse'), $order->warehouse?->name],
        [__('Created By'), $order->createdBy?->name],
        [__('Currency'), $order->currency?->code],
    ];
    $totals = [
        ...\Modules\Accounting\Support\PdfTotals::weights($lines),
        ...\Modules\Accounting\Support\PdfTotals::money($order, 'order_discount', __('Order Discount'), __('Total Price')),
    ];
    $notes = $order->notes;
    $signatures = [__('Customer Signature'), __('Employee Signature')];
@endphp

@extends('core::pdf.document')

@section('content')
    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th style="width: 13%;">{{ __('Barcode') }}</th>
                <th style="width: 10%;">{{ __('Total Quantity') }}</th>
                <th>{{ __('Item Name') }}</th>
                <th style="width: 10%;">{{ __('Unit Price') }}</th>
                <th style="width: 11%;">{{ __('Total Price') }}</th>
                <th style="width: 10%;">{{ __('Approx. Weight') }}</th>
                <th style="width: 10%;">{{ __('Actual Weight') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $item)
                @php $weighed = $item->isWeightPricedLine(); @endphp
                <tr class="main-row">
                    <td>{{ $loop->iteration }}</td>
                    <td class="ltr">{{ $item->barcode }}</td>
                    <td>{{ $weight($weighed ? $item->total_weight : $item->quantity) }}</td>
                    <td class="start">{{ $item->description }}</td>
                    <td>{{ $money($item->unit_price) }}</td>
                    <td>{{ $money($item->line_total) }}</td>
                    <td>{{ $weighed ? $weight($item->total_weight) : '-' }}</td>
                    <td>{{ $weighed ? ((float) $item->actual_weight ? $weight($item->actual_weight) : '.....') : '-' }}</td>
                </tr>

                @include('accounting::pdf.partials.item-pieces', ['item' => $item, 'number' => $loop->iteration, 'columns' => 8])
            @endforeach
        </tbody>
    </table>

    @include('accounting::pdf.partials.sales-services', ['lines' => $order->items->filter->isService()])
    @include('accounting::pdf.partials.customer-materials', ['materials' => $order->customerMaterials])
@endsection

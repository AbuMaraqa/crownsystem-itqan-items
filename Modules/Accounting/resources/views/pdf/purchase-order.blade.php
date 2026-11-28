{{-- طلبية شراء للمورد: البنود باصنافها الفرعية واوزانها، بلا اسعار. --}}
@php
    $quantity = fn ($value) => \Modules\Accounting\Support\Number::quantity($value);
    $lines = $order->items;

    $docTitle = __('Purchase Order');
    $party = ['name' => $order->client?->name];
    $meta = [
        [__('Order Date'), $order->order_date?->format('d/m/Y')],
        [__('Expected Delivery Date'), $order->expected_delivery_date?->format('d/m/Y')],
        [__('Order No'), $order->reference_no],
        [__('Warehouse'), $order->warehouse?->name],
        [__('Status'), $order->status?->getLabel()],
    ];
    $totals = \Modules\Accounting\Support\PdfTotals::weights($lines);
    $notes = $order->notes;
    $signatures = [__('Supplier Signature'), __('Employee Signature')];
@endphp

@extends('core::pdf.document')

@section('content')
    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th style="width: 14%;">{{ __('Barcode') }}</th>
                <th style="width: 12%;">{{ __('Total Quantity') }}</th>
                <th>{{ __('Item Name') }}</th>
                <th style="width: 12%;">{{ __('Approx. Weight') }}</th>
                <th style="width: 12%;">{{ __('Actual Weight') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $item)
                @php $weighed = $item->isWeightPricedLine(); @endphp
                <tr class="main-row">
                    <td>{{ $loop->iteration }}</td>
                    <td class="ltr">{{ $item->barcode }}</td>
                    <td>{{ $quantity($weighed ? $item->total_weight : $item->quantity) }}</td>
                    <td class="start">{{ $item->description }}</td>
                    <td>{{ $weighed ? $quantity($item->total_weight) : '-' }}</td>
                    <td>{{ $weighed ? ((float) $item->actual_weight ? $quantity($item->actual_weight) : '.....') : '-' }}</td>
                </tr>

                @include('accounting::pdf.partials.item-pieces', ['item' => $item, 'number' => $loop->iteration, 'columns' => 6])
            @endforeach
        </tbody>
    </table>
@endsection

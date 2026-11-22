<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    @include('core::pdf.partials.styles')
    <style>
        @page {
            header: doc-header;
            footer: doc-footer;
            margin-top: 32mm;
            margin-bottom: 14mm;
            margin-left: 10mm;
            margin-right: 10mm;
        }
    </style>
</head>
<body>
    @include('core::pdf.partials.header', ['docTitle' => __('Purchase Order')])
    @include('core::pdf.partials.footer')

    <div class="meta">
        <table>
            <tr>
                <td>
                    <span class="label">{{ __('Reference No') }}:</span>
                    {{ $order->reference_no }}
                </td>
                <td>
                    <span class="label">{{ __('Supplier') }}:</span>
                    {{ $order->client?->name }}
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">{{ __('Order Date') }}:</span>
                    {{ $order->order_date?->toDateString() }}
                </td>
                <td>
                    <span class="label">{{ __('Expected Delivery Date') }}:</span>
                    {{ $order->expected_delivery_date?->toDateString() ?? '-' }}
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">{{ __('Warehouse') }}:</span>
                    {{ $order->warehouse?->name }}
                </td>
                <td>
                    <span class="label">{{ __('Status') }}:</span>
                    {{ $order->status?->getLabel() }}
                </td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th>{{ __('Item') }}</th>
                <th style="width: 12%;">{{ __('Barcode') }}</th>
                <th class="text-end" style="width: 14%;">{{ __('Quantity') }} / {{ __('Total Weight') }}</th>
                <th class="text-end" style="width: 14%;">{{ __('Actual Weight') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                @php $isWeightPriced = $item->isPriceGroupLine() || $item->product?->pricing_type === 'weight'; @endphp
                <tr class="main-row">
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->barcode }}</td>
                    <td class="text-end">{{ number_format((float) ($isWeightPriced ? $item->total_weight : $item->quantity), 3) }}</td>
                    <td class="text-end">{{ $isWeightPriced ? number_format((float) $item->actual_weight, 3) : '-' }}</td>
                </tr>

                @include('accounting::pdf.partials.item-pieces', ['item' => $item, 'number' => $loop->iteration, 'columns' => 5])
            @endforeach
        </tbody>
    </table>

    @if ($order->notes)
        <div class="notes">
            <div class="label">{{ __('Notes') }}</div>
            <div>{{ $order->notes }}</div>
        </div>
    @endif

    <table class="signatures">
        <tr>
            <td class="spacer"></td>
            <td class="spacer"></td>
        </tr>
        <tr>
            <td>{{ __('Client Signature') }}</td>
            <td>{{ __('Employee Signature') }}</td>
        </tr>
    </table>
</body>
</html>

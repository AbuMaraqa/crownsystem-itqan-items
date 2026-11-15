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
    @include('core::pdf.partials.header', ['docTitle' => __('Sales Order')])
    @include('core::pdf.partials.footer')

    <div class="meta">
        <table>
            <tr>
                <td>
                    <span class="label">{{ __('Reference No') }}:</span>
                    {{ $order->reference_no }}
                </td>
                <td>
                    <span class="label">{{ __('Client') }}:</span>
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
                    <span class="label">{{ __('Currency') }}:</span>
                    {{ $order->currency?->code }}
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
                <th class="text-end" style="width: 9%;">{{ __('Quantity') }} / {{ __('Total Weight') }}</th>
                <th class="text-end" style="width: 9%;">{{ __('Actual Weight') }}</th>
                <th class="text-end" style="width: 10%;">{{ __('Price') }}</th>
                <th class="text-end" style="width: 10%;">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items->reject->isService() as $item)
                @php $isWeightPriced = $item->product?->pricing_type === 'weight'; @endphp
                <tr class="main-row">
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->barcode }}</td>
                    <td class="text-end">{{ number_format((float) ($isWeightPriced ? $item->total_weight : $item->quantity), 3) }}</td>
                    <td class="text-end">{{ $isWeightPriced ? number_format((float) $item->actual_weight, 3) : '-' }}</td>
                    <td class="text-end">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>

                @include('accounting::pdf.partials.item-pieces', ['item' => $item, 'number' => $loop->iteration, 'columns' => 7])
            @endforeach
        </tbody>
    </table>

    @include('accounting::pdf.partials.sales-services', ['lines' => $order->items->filter->isService()])
    @include('accounting::pdf.partials.customer-materials', ['materials' => $order->customerMaterials])

    <div class="totals">
        <table>
            <tr>
                <td class="label">{{ __('Subtotal') }}</td>
                <td class="value">{{ number_format((float) $order->sub_total, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Items Discount') }}</td>
                <td class="value">{{ number_format((float) $order->items_discount, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Order Discount') }}</td>
                <td class="value">{{ number_format((float) $order->order_discount, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Tax') }} ({{ number_format((float) $order->tax_rate, 2) }}%)</td>
                <td class="value">{{ number_format((float) $order->tax_amount, 2) }}</td>
            </tr>
            <tr class="grand-total">
                <td class="label">{{ __('Total Amount') }}</td>
                <td class="value">{{ number_format((float) $order->total_amount, 2) }}</td>
            </tr>
        </table>
    </div>

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
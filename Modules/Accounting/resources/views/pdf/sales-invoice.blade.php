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
    @include('core::pdf.partials.header', ['docTitle' => __('Sales Invoice')])
    @include('core::pdf.partials.footer')

    <div class="meta">
        <table>
            <tr>
                <td>
                    <span class="label">{{ __('Reference No') }}:</span>
                    {{ $invoice->reference_no }}
                </td>
                <td>
                    <span class="label">{{ __('Client') }}:</span>
                    {{ $invoice->client?->name }}
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">{{ __('Invoice Date') }}:</span>
                    {{ $invoice->invoice_date?->toDateString() }}
                </td>
                <td>
                    <span class="label">{{ __('Due Date') }}:</span>
                    {{ $invoice->due_date?->toDateString() ?? '-' }}
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">{{ __('Currency') }}:</span>
                    {{ $invoice->currency?->code }}
                </td>
                <td>
                    <span class="label">{{ __('Tax') }}:</span>
                    {{ $invoice->tax?->name ?? __('No tax') }}
                </td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th>{{ __('Item') }}</th>
                <th>{{ __('Barcode') }}</th>
                <th class="text-end">{{ __('Quantity') }}</th>
                <th class="text-end">{{ __('Price') }}</th>
                <th class="text-end">{{ __('Discount') }}</th>
                <th class="text-end">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items->reject->isService()->values() as $item)
                <tr class="main-row">
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->barcode }}</td>
                    <td class="text-end">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $item->discount_amount, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>

                @include('accounting::pdf.partials.item-pieces', ['item' => $item, 'number' => $loop->iteration, 'columns' => 7])
            @endforeach
        </tbody>
    </table>

    @include('accounting::pdf.partials.sales-services', ['lines' => $invoice->items->filter->isService()])
    @include('accounting::pdf.partials.customer-materials', ['materials' => $invoice->salesOrder?->customerMaterials ?? collect()])

    <div class="totals">
        <table>
            <tr>
                <td class="label">{{ __('Subtotal') }}</td>
                <td class="value">{{ number_format((float) $invoice->sub_total, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Items Discount') }}</td>
                <td class="value">{{ number_format((float) $invoice->items_discount, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Invoice Discount') }}</td>
                <td class="value">{{ number_format((float) $invoice->invoice_discount, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Tax') }} ({{ number_format((float) $invoice->tax_rate, 2) }}%)</td>
                <td class="value">{{ number_format((float) $invoice->tax_amount, 2) }}</td>
            </tr>
            <tr class="grand-total">
                <td class="label">{{ __('Balance Due') }}</td>
                <td class="value">{{ number_format((float) $invoice->total_amount, 2) }}</td>
            </tr>
        </table>
    </div>

    @if ($invoice->notes)
        <div class="notes">
            <div class="label">{{ __('Notes') }}</div>
            <div>{{ $invoice->notes }}</div>
        </div>
    @endif
</body>
</html>

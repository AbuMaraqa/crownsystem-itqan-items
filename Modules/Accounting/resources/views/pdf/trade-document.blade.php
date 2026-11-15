{{-- فاتورة مشتريات / مردود مبيعات / مردود مشتريات: بيانات، بنود، مجاميع --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    @include('core::pdf.partials.styles')
    <style>
        @page { header: doc-header; footer: doc-footer; margin-top: 32mm; margin-bottom: 14mm; margin-left: 10mm; margin-right: 10mm; }
    </style>
</head>
<body>
    @include('core::pdf.partials.header', ['docTitle' => $docTitle])
    @include('core::pdf.partials.footer')

    <div class="meta">
        <table>
            @foreach (array_chunk($meta, 2) as $row)
                <tr>
                    @foreach ($row as [$label, $value])
                        <td><span class="label">{{ $label }}:</span> {{ $value ?? '-' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th>{{ __('Item') }}</th>
                <th class="text-end">{{ __('Quantity') }}</th>
                <th class="text-end">{{ __('Price') }}</th>
                <th class="text-end">{{ __('Discount') }}</th>
                <th class="text-end">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->items as $index => $item)
                <tr class="main-row">
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="text-end">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $item->discount_amount, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>

                @if ($item->relationLoaded('pieces'))
                    @include('accounting::pdf.partials.item-pieces', ['item' => $item, 'number' => $index + 1, 'columns' => 6, 'barcodeColumn' => false])
                @endif
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr>
                <td class="label">{{ __('Subtotal') }}</td>
                <td class="value">{{ number_format((float) $document->sub_total, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Items Discount') }}</td>
                <td class="value">{{ number_format((float) $document->items_discount, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ $discountLabel ?? __('Invoice Discount') }}</td>
                <td class="value">{{ number_format((float) $document->{$discountColumn ?? 'invoice_discount'}, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Tax') }} ({{ number_format((float) $document->tax_rate, 2) }}%)</td>
                <td class="value">{{ number_format((float) $document->tax_amount, 2) }}</td>
            </tr>
            <tr class="grand-total">
                <td class="label">{{ __('Total') }}</td>
                <td class="value">{{ number_format((float) $document->total_amount, 2) }} {{ $document->currency?->code }}</td>
            </tr>
        </table>
    </div>

    @if ($document->notes)
        <div class="notes">
            <div class="label">{{ __('Notes') }}</div>
            <div>{{ $document->notes }}</div>
        </div>
    @endif
</body>
</html>

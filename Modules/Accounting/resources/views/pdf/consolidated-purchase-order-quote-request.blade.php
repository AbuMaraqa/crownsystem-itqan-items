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
    @include('core::pdf.partials.header', ['docTitle' => __('Quote Request')])
    @include('core::pdf.partials.footer')

    <p>{{ now()->toDateString() }}</p>

    <p><strong>{{ __('To') }}:</strong> {{ $supplier->name }}</p>

    <h3>{{ __('New Quote Request') }} - {{ $order->reference_no }}</h3>

    <p>{{ __('Please provide your best price offer for the below mentioned items:') }}</p>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th>{{ __('Description') }}</th>
                <th class="text-end" style="width: 15%;">{{ __('Quantity') }}</th>
                <th class="text-end" style="width: 20%;">{{ __('Price') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($order->items as $item)
                @php $isWeightPriced = $item->product?->pricing_type === 'weight'; @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="text-end">{{ number_format((float) ($isWeightPriced ? $item->total_weight : $item->quantity), 3) }}</td>
                    <td class="text-end"></td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center">{{ __('No data available') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>

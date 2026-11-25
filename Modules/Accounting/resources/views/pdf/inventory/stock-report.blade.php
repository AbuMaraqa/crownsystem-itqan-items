@php
    $fmt = fn ($value, $decimals = 2) => \Modules\Accounting\Support\Quantity::format($value, $decimals);
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    @include('core::pdf.partials.styles')
    <style>
        @page { header: doc-header; footer: doc-footer; margin-top: 32mm; margin-bottom: 14mm; margin-left: 8mm; margin-right: 8mm; }
        h2.group { font-size: 13pt; background: #0c456f; color: #fff; padding: 4px 8px; margin: 4mm 0 0; }
        table.items td.qty { background: #e5e7eb; font-weight: bold; text-align: center; }
        table.items td.num, table.items th.num { text-align: center; }
        {{-- لونا الحدين الادنيين من الاعدادات العامة (DashboardTheme::stockColors) --}}
        .low { color: {{ $stockColors['min_1'] }}; font-weight: bold; }
        .critical { color: {{ $stockColors['min_2'] }}; font-weight: bold; }
        .out { color: #b91c1c; font-weight: bold; }
        .section img { max-height: 11mm; max-width: 30mm; }
    </style>
</head>
<body>
    @include('core::pdf.partials.header', ['docTitle' => __('Stock Report')])
    @include('core::pdf.partials.footer')

    <div class="meta">
        <table>
            <tr>
                <td><span class="label">{{ __('Warehouse') }}:</span> {{ $warehouse?->name ?? __('All warehouses') }}</td>
                <td><span class="label">{{ __('Printed at') }}:</span> {{ now()->format('d/m/Y H:i') }}</td>
            </tr>
            @if ($statusLabel)
                <tr><td colspan="2"><span class="label">{{ __('Status') }}:</span> {{ $statusLabel }}</td></tr>
            @endif
        </table>
    </div>

    @foreach ($groups as $groupName => $items)
        <h2 class="group">{{ $groupName }} <span style="font-size: 9pt; font-weight: normal;">({{ $items->count() }})</span></h2>
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 13%;">{{ __('Section') }}</th>
                    <th style="width: 9%;">{{ __('Barcode') }}</th>
                    <th>{{ __('Item Name') }}</th>
                    <th class="num" style="width: 7%;">{{ __('Location') }}</th>
                    <th class="num" style="width: 6%;">{{ __('Max Limit') }}</th>
                    <th class="num" style="width: 6%;">{{ __('Min Limit 1') }}</th>
                    <th class="num" style="width: 6%;">{{ __('Min Limit 2') }}</th>
                    <th class="num" style="width: 8%;">{{ __('Item Group') }}</th>
                    <th class="num" style="width: 7%;">{{ __('Quantity') }}</th>
                    <th class="num" style="width: 7%;">{{ __('Sales Orders Qty') }}</th>
                    <th class="num" style="width: 7%;">{{ __('Purchase Orders Qty') }}</th>
                    <th class="num" style="width: 7%;">{{ __('Weight (kg)') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $product)
                    @php
                        $status = \Modules\Accounting\Support\StockStatus::for($product, (float) $product->stock_on_hand);
                        $media = $product->getFirstMedia('product');
                        $productLocations = collect($locations[$product->id] ?? [])->pluck('code')->join(' ، ');
                    @endphp
                    <tr>
                        <td class="section">@if ($media && is_file($media->getPath()))<img src="{{ $media->getPath() }}" alt="" style="width: 28mm;">@endif</td>
                        <td dir="ltr" style="text-align: right;">{{ $product->barcode }}</td>
                        <td>{{ $product->name }}</td>
                        <td class="num" style="direction: ltr;">{{ $productLocations ?: $product->shelf }}</td>
                        <td class="num">{{ $fmt($product->max_limit, 0) }}</td>
                        <td class="num">{{ $fmt($product->min_limit_1, 0) }}</td>
                        <td class="num">{{ $fmt($product->min_limit_2, 0) }}</td>
                        <td class="num">{{ $product->categories->first()?->name }}</td>
                        <td class="qty {{ match ($status->value) { 'out' => 'out', 'critical' => 'critical', 'low' => 'low', default => '' } }}">{{ $fmt($product->stock_on_hand) }}</td>
                        <td class="num">{{ $fmt($reserved[$product->id] ?? 0) }}</td>
                        <td class="num">{{ $fmt($incoming[$product->id] ?? 0) }}</td>
                        <td class="num">{{ $fmt($product->stock_weight) }}</td>
                    </tr>
                @endforeach
                <tr class="main-row">
                    <td colspan="8">{{ __('Total') }}</td>
                    <td class="num">{{ $fmt($items->sum('stock_on_hand')) }}</td>
                    <td colspan="2"></td>
                    <td class="num">{{ $fmt($items->sum('stock_weight')) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach

    @if ($groups->isEmpty())
        <p style="text-align: center; margin-top: 20mm;">{{ __('No stock to show.') }}</p>
    @endif
</body>
</html>

{{-- تقرير الارصدة: جدول لكل مجموعة اصناف، بقالب المطبوعات الموحد. --}}
@php
    $fmt = fn ($value, $decimals = 2) => \Modules\Accounting\Support\Quantity::format($value, $decimals);
    $docTitle = __('Stock Report');
    $meta = [
        [__('Warehouse'), $warehouse?->name ?? __('All warehouses')],
        [__('Printed at'), now()->format('d/m/Y H:i')],
        $statusLabel ? [__('Status'), $statusLabel] : null,
    ];
@endphp

@extends('core::pdf.document')

@push('styles')
    <style>
        table.items td.qty { background-color: #f0f0f0; font-weight: bold; }
        {{-- لونا الحدين الادنيين من الاعدادات العامة (DashboardTheme::stockColors) --}}
        .low { color: {{ $stockColors['min_1'] }}; font-weight: bold; }
        .critical { color: {{ $stockColors['min_2'] }}; font-weight: bold; }
        .out { color: #b91c1c; font-weight: bold; }
        .section img { max-height: 11mm; max-width: 30mm; }
    </style>
@endpush

@section('content')
    @foreach ($groups as $groupName => $items)
        <h2 class="section-title">{{ $groupName }} <span style="font-size: 9pt; font-weight: normal;">({{ $items->count() }})</span></h2>
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 10%;">{{ __('Section') }}</th>
                    <th style="width: 8%;">{{ __('Item Number') }}</th>
                    <th style="width: 8%;">{{ __('Barcode') }}</th>
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
                        <td class="ltr" style="font-weight: bold;">{{ $product->sku }}</td>
                        <td class="ltr">{{ $product->barcode }}</td>
                        <td class="start">{{ $product->name }}</td>
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
                <tr class="total-row">
                    <td colspan="9">{{ __('Total') }}</td>
                    <td class="num">{{ $fmt($items->sum('stock_on_hand')) }}</td>
                    <td colspan="2"></td>
                    <td class="num">{{ $fmt($mainWeights[$groupName] ?? $items->sum('stock_weight')) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach

    @if ($groups->isEmpty())
        <p style="text-align: center; margin-top: 20mm;">{{ __('No stock to show.') }}</p>
    @endif
@endsection

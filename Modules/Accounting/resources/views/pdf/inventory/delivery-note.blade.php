@php
    $fmt = fn ($value, $decimals = 2) => \Modules\Accounting\Support\Quantity::format($value, $decimals);
    // المسودة تُطبع «قائمة تحضير» بمواقع الاصناف، والمرحّل «سند تسليم» بما سُحب من كل موقع.
    $docTitle = $document->isDraft() ? __('Pick List') : __('Delivery Note');
    $meta = [
        [__('Reference No'), $document->reference_no],
        [__('Status'), $document->status?->getLabel()],
        [__('Sales Order'), $document->salesOrder?->reference_no],
        [__('Customer'), $document->client?->name],
        [__('Warehouse'), $document->warehouse?->name],
        [__('Delivery Date'), $document->delivery_date?->format('d/m/Y')],
        [__('Driver Name'), $document->driver_name],
        [__('Vehicle No'), $document->vehicle_no],
        [__('Delivery Address'), $document->delivery_address],
        [__('Receiver Name'), $document->receiver_name],
    ];
    $signatures = [__('Storekeeper'), __('Driver'), __('Customer Signature')];
@endphp

@extends('accounting::pdf.inventory.layout')

@section('items')
    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 12%;">{{ __('Barcode') }}</th>
                <th>{{ __('Item') }}</th>
                <th class="num" style="width: 9%;">{{ __('Length') }}</th>
                <th class="num" style="width: 10%;">{{ __('Quantity') }}</th>
                <th class="num" style="width: 10%;">{{ __('Weight (kg)') }}</th>
                <th class="num" style="width: 18%;">{{ __('Pick From') }}</th>
                @if ($document->isDraft())
                    <th class="num" style="width: 7%;">{{ __('Picked') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($document->items as $index => $item)
                @php
                    if ($document->isDraft()) {
                        $picks = $item->location
                            ? [$item->location->code]
                            : collect($placements[$item->product_id] ?? [])->map(fn ($p) => $p['code'].($p['quantity'] ? ' ('.$fmt($p['quantity']).')' : ''))->all();
                    } else {
                        $picks = collect($item->picked_locations ?? [])->map(fn ($p) => ($p['code'] ?? '—').' × '.$fmt($p['quantity']))->all();
                    }
                @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td style="direction: ltr; text-align: right;">{{ $item->barcode }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ (float) $item->piece_length ? $fmt($item->piece_length, 3) : '' }}</td>
                    <td class="big">{{ $fmt($item->quantity) }}</td>
                    <td class="num">{{ $fmt($item->weight) }}</td>
                    <td class="num pick">{{ implode(' ، ', $picks) }}</td>
                    @if ($document->isDraft())
                        <td class="num" style="border: 1px solid #9ca3af;"></td>
                    @endif
                </tr>
            @endforeach
            <tr class="main-row">
                <td colspan="4">{{ __('Total') }}</td>
                <td class="num">{{ $fmt($document->total_quantity) }}</td>
                <td class="num">{{ $fmt($document->total_weight) }}</td>
                <td colspan="{{ $document->isDraft() ? 2 : 1 }}"></td>
            </tr>
        </tbody>
    </table>
@endsection

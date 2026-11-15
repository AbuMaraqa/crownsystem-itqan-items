@php
    $fmt = fn ($value, $decimals = 2) => \Modules\Accounting\Support\Quantity::format($value, $decimals);
    $docTitle = __('Stock Transfer');
    $meta = [
        [__('Reference No'), $document->reference_no],
        [__('Status'), $document->status?->getLabel()],
        [__('From Warehouse'), $document->fromWarehouse?->name],
        [__('To Warehouse'), $document->toWarehouse?->name],
        [__('Transfer Date'), $document->transfer_date?->format('d/m/Y')],
        [__('Created By'), $document->createdBy?->name],
    ];
    $signatures = [__('Sending Storekeeper'), __('Receiving Storekeeper'), __('Approved By')];
@endphp

@extends('accounting::pdf.inventory.layout')

@section('items')
    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 13%;">{{ __('Barcode') }}</th>
                <th>{{ __('Item') }}</th>
                <th class="num" style="width: 10%;">{{ __('Quantity') }}</th>
                <th class="num" style="width: 10%;">{{ __('Weight (kg)') }}</th>
                <th class="num" style="width: 11%;">{{ __('From Location') }}</th>
                <th class="num" style="width: 11%;">{{ __('To Location') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td style="direction: ltr; text-align: right;">{{ $item->barcode }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="big">{{ $fmt($item->quantity) }}</td>
                    <td class="num">{{ $fmt($item->weight) }}</td>
                    <td class="num pick">{{ $item->fromLocation?->code ?? '—' }}</td>
                    <td class="num pick">{{ $item->toLocation?->code ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection

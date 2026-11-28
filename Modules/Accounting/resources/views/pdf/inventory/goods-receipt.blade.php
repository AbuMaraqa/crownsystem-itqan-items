@php
    $fmt = fn ($value, $decimals = 2) => \Modules\Accounting\Support\Quantity::format($value, $decimals);
    $docTitle = __('Goods Receipt');
    $meta = [
        [__('Reference No'), $document->reference_no],
        [__('Status'), $document->status?->getLabel()],
        [__('Purchase Order'), $document->purchaseOrder?->reference_no],
        [__('Warehouse'), $document->warehouse?->name],
        [__('Receipt Date'), $document->receipt_date?->format('d/m/Y')],
        [__('Supplier Document No'), $document->supplier_document_no],
        [__('Container No'), $document->container_no],
    ];
    $party = ['name' => $document->client?->name];
    $notes = $document->notes;
    $signatures = [__('Received By').': '.($document->received_by_name ?? ''), __('Storekeeper'), __('Approved By')];
@endphp

@extends('core::pdf.document')

@section('content')
    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 12%;">{{ __('Barcode') }}</th>
                <th>{{ __('Item') }}</th>
                <th class="num" style="width: 9%;">{{ __('Length') }}</th>
                <th class="num" style="width: 10%;">{{ __('Received Qty') }}</th>
                <th class="num" style="width: 11%;">{{ __('Weight (kg)') }}</th>
                <th class="num" style="width: 10%;">{{ __('Location') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="ltr">{{ $item->barcode }}</td>
                    <td class="start">{{ $item->description }}</td>
                    <td class="num">{{ (float) $item->piece_length ? $fmt($item->piece_length, 3) : '' }}</td>
                    <td class="big">{{ $fmt($item->quantity) }}</td>
                    <td class="num">{{ $fmt($item->weight) }}</td>
                    <td class="num pick">{{ $item->location?->code }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="4">{{ __('Total') }}</td>
                <td class="num">{{ $fmt($document->total_quantity) }}</td>
                <td class="num">{{ $fmt($document->total_weight) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
@endsection

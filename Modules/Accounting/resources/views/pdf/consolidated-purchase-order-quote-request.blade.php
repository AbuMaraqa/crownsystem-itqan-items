{{-- طلب عرض سعر للمورد من الطلبية المجمعة: البنود بكمياتها وخانة فارغة للسعر. --}}
@php
    $docTitle = __('Quote Request');
    $party = ['name' => $supplier->name];
    $meta = [
        [__('Date'), now()->format('d/m/Y')],
        [__('Reference No'), $order->reference_no],
    ];
    $signatures = [__('Supplier Signature'), __('Employee Signature')];
@endphp

@extends('core::pdf.document')

@section('content')
    <p style="margin-bottom: 3mm;">{{ __('Please provide your best price offer for the below mentioned items:') }}</p>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th>{{ __('Item Name') }}</th>
                <th style="width: 15%;">{{ __('Quantity') }}</th>
                <th style="width: 20%;">{{ __('Unit Price') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($order->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td class="start">{{ $item->description }}</td>
                    <td>{{ \Modules\Accounting\Support\Number::quantity($item->isWeightPricedLine() ? $item->total_weight : $item->quantity) }}</td>
                    <td></td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">{{ __('No data available') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection

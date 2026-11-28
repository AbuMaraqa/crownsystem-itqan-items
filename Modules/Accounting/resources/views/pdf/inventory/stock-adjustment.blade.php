@php
    use Modules\Accounting\Enums\StockAdjustmentType;

    $fmt = fn ($value, $decimals = 2) => \Modules\Accounting\Support\Quantity::format($value, $decimals);
    $isCount = $document->type === StockAdjustmentType::COUNT;
    // ورقة الجرد (مسودة) تُطبع بخانة فارغة ليكتب العامل العدد الفعلي.
    $blankSheet = $isCount && $document->isDraft();
    $docTitle = $blankSheet ? __('Count Sheet') : $document->type->getLabel();
    $meta = [
        [__('Reference No'), $document->reference_no],
        [__('Status'), $document->status?->getLabel()],
        [__('Warehouse'), $document->warehouse?->name],
        [__('Date'), $document->adjustment_date?->format('d/m/Y')],
        [__('Reason'), $document->reason],
        [__('Created By'), $document->createdBy?->name],
    ];
    $notes = $document->notes;
    $signatures = [__('Counted By'), __('Storekeeper'), __('Approved By')];
@endphp

@extends('core::pdf.document')

@section('content')
    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 13%;">{{ __('Barcode') }}</th>
                <th>{{ __('Item') }}</th>
                <th class="num" style="width: 9%;">{{ __('Location') }}</th>
                @if ($isCount)
                    <th class="num" style="width: 10%;">{{ __('System Qty') }}</th>
                    <th class="num" style="width: 12%;">{{ __('Counted Qty') }}</th>
                    @unless ($blankSheet)
                        <th class="num" style="width: 10%;">{{ __('Difference') }}</th>
                    @endunless
                @else
                    <th class="num" style="width: 10%;">{{ __('Quantity') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($document->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="ltr">{{ $item->barcode }}</td>
                    <td class="start">{{ $item->description }}</td>
                    <td class="num pick">{{ $item->location?->code }}</td>
                    @if ($isCount)
                        <td class="num">{{ $blankSheet ? '' : $fmt($item->system_quantity) }}</td>
                        <td class="big">{{ $blankSheet ? '' : $fmt($item->counted_quantity) }}</td>
                        @unless ($blankSheet)
                            <td class="num">{{ ((float) $item->quantity > 0 ? '+' : '').$fmt($item->quantity) }}</td>
                        @endunless
                    @else
                        <td class="big">{{ $fmt($item->quantity) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection

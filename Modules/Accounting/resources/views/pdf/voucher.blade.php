{{-- سند قبض / سند صرف: الطرف، المبلغ، طريقة الدفع وبيانات الشيك، التواقيع --}}
@php
    $isCheque = $voucher->payment_method === \Modules\Accounting\Enums\AccountingPaymentMethod::CHEQUE;

    $party = ['label' => $partyLabel, 'name' => $voucher->client?->name];
    $meta = array_values(array_filter([
        [__('Date'), $voucher->voucher_date?->format('d/m/Y')],
        [__('Reference No'), $voucher->reference_no],
        [__('Account'), $voucher->account?->name],
        [__('Payment Method'), $voucher->payment_method?->getLabel()],
        [__('Status'), $voucher->status?->getLabel()],
        $isCheque ? [__('Cheque Number'), $voucher->cheque_number] : null,
        $isCheque ? [__('Cheque Bank'), $voucher->cheque_bank_name] : null,
        $isCheque ? [__('Cheque Date'), $voucher->cheque_date?->format('d/m/Y')] : null,
        (float) $voucher->exchange_rate != 1.0 ? [__('Exchange Rate'), number_format((float) $voucher->exchange_rate, 4)] : null,
    ]));
    $totals = [[__('Amount'), number_format((float) $voucher->amount, 2).' '.$voucher->currency?->code, true]];
    $notes = $voucher->notes;
    $signatures = [__('Accountant'), __('Received By'), __('Approved By')];
@endphp

@extends('core::pdf.document')

@section('content')
@endsection

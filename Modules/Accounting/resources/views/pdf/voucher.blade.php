{{-- سند قبض / سند صرف: الطرف، المبلغ، طريقة الدفع وبيانات الشيك، التواقيع --}}
@php
    $isCheque = $voucher->payment_method === \Modules\Accounting\Enums\AccountingPaymentMethod::CHEQUE;
    $meta = array_values(array_filter([
        [__('Reference No'), $voucher->reference_no],
        [__('Date'), $voucher->voucher_date?->toDateString()],
        [$partyLabel, $voucher->client?->name],
        [__('Account'), $voucher->account?->name],
        [__('Payment Method'), $voucher->payment_method?->getLabel()],
        [__('Status'), $voucher->status?->getLabel()],
        $isCheque ? [__('Cheque Number'), $voucher->cheque_number] : null,
        $isCheque ? [__('Cheque Bank'), $voucher->cheque_bank_name] : null,
        $isCheque ? [__('Cheque Date'), $voucher->cheque_date?->toDateString()] : null,
        [__('Exchange Rate'), (float) $voucher->exchange_rate != 1.0 ? number_format((float) $voucher->exchange_rate, 4) : null],
    ]));
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    @include('core::pdf.partials.styles')
    <style>
        @page { header: doc-header; footer: doc-footer; margin-top: 32mm; margin-bottom: 14mm; margin-left: 10mm; margin-right: 10mm; }
        .amount-box { margin-top: 6mm; padding: 4mm; border: 1.2pt solid #0c456f; text-align: center; font-size: 16pt; font-weight: bold; }
        table.signatures { width: 100%; margin-top: 18mm; }
        table.signatures td { width: 33%; text-align: center; padding-top: 10mm; border-top: 1px dashed #9ca3af; font-size: 10px; color: #4b5563; }
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

    <div class="amount-box">
        {{ __('Amount') }}: {{ number_format((float) $voucher->amount, 2) }} {{ $voucher->currency?->code }}
    </div>

    @if ($voucher->notes)
        <div class="notes">
            <div class="label">{{ __('Notes') }}</div>
            <div>{{ $voucher->notes }}</div>
        </div>
    @endif

    <table class="signatures">
        <tr>
            <td>{{ __('Accountant') }}</td>
            <td>{{ __('Received By') }}</td>
            <td>{{ __('Approved By') }}</td>
        </tr>
    </table>
</body>
</html>

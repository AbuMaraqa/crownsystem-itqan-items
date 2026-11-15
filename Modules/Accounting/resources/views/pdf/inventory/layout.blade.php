{{-- قالب مستندات المخزن: الترويسة، البيانات، الجدول (من القالب الابن)، التواقيع --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    @include('core::pdf.partials.styles')
    <style>
        @page { header: doc-header; footer: doc-footer; margin-top: 32mm; margin-bottom: 14mm; margin-left: 10mm; margin-right: 10mm; }
        table.items td.num, table.items th.num { text-align: center; }
        table.items td.big { font-size: 12px; font-weight: bold; text-align: center; background: #f3f4f6; }
        .status { display: inline-block; padding: 1px 6px; border: 1px solid #9ca3af; font-size: 9px; }
        table.signatures { width: 100%; margin-top: 12mm; }
        table.signatures td { width: 33%; text-align: center; padding-top: 10mm; border-top: 1px dashed #9ca3af; font-size: 10px; color: #4b5563; }
        .pick { direction: ltr; font-weight: bold; }
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

    @yield('items')

    @if (filled($document->notes ?? null))
        <p style="margin-top: 4mm;"><b>{{ __('Notes') }}:</b> {{ $document->notes }}</p>
    @endif

    <table class="signatures">
        <tr>
            @foreach ($signatures as $signature)
                <td>{{ $signature }}</td>
            @endforeach
        </tr>
    </table>
</body>
</html>

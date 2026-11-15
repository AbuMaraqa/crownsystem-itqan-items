<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'tajawal', sans-serif; direction: rtl; color: #111827; }
        table.sheet { width: 100%; border-collapse: collapse; }
        td.slot { width: 50%; padding: 3mm; vertical-align: top; text-align: center; }
        /* الاطار الازرق السميك (كبطاقات الاعمدة): خلية زرقاء بحشوة، وداخلها جدول ابيض؛
           mPDF لا يرسم حدود div السميكة بشكل صحيح */
        table.frame { width: 90mm; border-collapse: collapse; }
        td.frame-cell { background-color: #1d4f91; padding: 3mm; }
        table.label { width: 100%; background-color: #ffffff; border-collapse: collapse; }
        table.label td { text-align: center; padding: 1mm 3mm; }
        .barcode { font-size: 18pt; font-weight: bold; padding-top: 3mm !important; }
        .name { font-size: 13pt; font-weight: bold; }
        .code { font-size: 16pt; font-weight: bold; }
        .location { font-size: 12pt; font-weight: bold; color: #1d4f91; text-align: right; }
        .arrow { font-family: dejavusans; font-size: 28pt; color: #1d4f91; text-align: left; }
    </style>
</head>
<body>
    @foreach ($labels->chunk(4) as $pageIndex => $page)
        @if ($pageIndex > 0)
            <pagebreak />
        @endif
        <table class="sheet">
            @foreach ($page->chunk(2) as $row)
                <tr>
                    @foreach ($row as $label)
                        <td class="slot">
                            <table class="frame"><tr><td class="frame-cell">
                            <table class="label">
                                <tr><td class="barcode" dir="ltr">{{ $label['barcode'] }}</td></tr>
                                <tr>
                                    <td style="height: 30mm;">
                                        @if ($label['image'])
                                            <img src="{{ $label['image'] }}" alt="" style="width: 62mm;">
                                        @endif
                                    </td>
                                </tr>
                                <tr><td class="name">{{ $label['name'] }}</td></tr>
                                <tr><td class="code" dir="ltr">{{ $label['code'] }}</td></tr>
                                <tr><td><img src="{{ $label['qr'] }}" alt="" style="width: 30mm; height: 30mm;"></td></tr>
                                <tr>
                                    <td style="padding-bottom: 2mm;">
                                        <table style="width: 100%;">
                                            <tr>
                                                <td class="location" dir="ltr" style="width: 50%;">{{ $label['location'] }}</td>
                                                <td class="arrow" style="width: 50%;">{{ $label['arrow'] }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            </td></tr></table>
                        </td>
                    @endforeach
                    @if ($row->count() < 2)
                        <td class="slot"></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endforeach

    @if ($labels->isEmpty())
        <p style="text-align: center; margin-top: 40mm;">{{ __('No items to print.') }}</p>
    @endif
</body>
</html>

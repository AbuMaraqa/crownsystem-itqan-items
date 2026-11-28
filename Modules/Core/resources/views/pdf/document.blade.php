{{-- قالب كل مطبوعات النظام (مستندات وتقارير): ترويسة الشركة، العنوان ومربع البيانات في رأس كل صفحة،
     ثم المحتوى، المجاميع، الملاحظات والتواقيع، وشكل الزاوية في ذيل كل صفحة.
     يضبطه القالب الابن قبل @extends('core::pdf.document'):
       $docTitle    عنوان المستند
       $party       ['label' => 'السادة', 'name' => ..., 'detail' => ...] او null
       $meta        [[عنوان, قيمة], ...]  التواريخ والارقام بجانب الطرف
       $totals      [[عنوان, قيمة, bool اجمالي؟], ...]  مربعات المجاميع بعد الجداول
       $notes       نص الملاحظات
       $signatures  [عنوان, ...]  خانات التوقيع
     والجداول في @section('content') بالصنف table.items. --}}
@php
    $letterhead = \Modules\Core\Support\Letterhead::data();
    $party = $party ?? null;
    $meta = array_values(array_filter($meta ?? [], fn ($row) => $row !== null));
    $totals = array_values(array_filter($totals ?? [], fn ($row) => $row !== null));
    $signatures = $signatures ?? [];
    $notes = $notes ?? null;

    // بجانب الطرف عمود او عمودان من البيانات؛ وبلا طرف تملأ البيانات عرض المربع.
    $metaColumns = $metaColumns ?? ($party ? (count($meta) > 4 ? 2 : 1) : (count($meta) > 6 ? 3 : 2));
    $infoRows = max((int) ceil(count($meta) / max($metaColumns, 1)), $party ? 2 : 0);

    // ارتفاع رأس الصفحة التقريبي (بالمليمتر) = هامش + ترويسة + عنوان + مربع البيانات؛ حد ادنى، ويمدّه
    // mPDF ان طال الرأس (PdfService: setAutoTopMargin = stretch).
    $headerHeight = 8 + 25 + 12 + ($infoRows ? $infoRows * 6 + 4 : 0) + 3;
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    @include('core::pdf.partials.styles')
    <style>
        @page {
            header: doc-header;
            footer: doc-footer;
            margin-header: 8mm;
            margin-footer: 6mm;
            margin-top: {{ $headerHeight }}mm;
            margin-bottom: 30mm;
            margin-left: 10mm;
            margin-right: 10mm;
        }
    </style>
    @stack('styles')
</head>
<body>
    @include('core::pdf.partials.header')
    @include('core::pdf.partials.footer')

    @yield('content')

    @if ($totals)
        <table class="totals">
            @foreach (array_chunk($totals, 3) as $row)
                <tr>
                    @foreach ($row as $total)
                        <td class="label">{{ $total[0] }}</td>
                        <td class="{{ ($total[2] ?? false) ? 'grand' : 'value' }}">{{ $total[1] }}</td>
                    @endforeach
                    @for ($i = count($row); $i < 3; $i++)
                        <td></td><td style="border: none;"></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif

    @if (filled($notes))
        <div class="notes">
            <div class="label">{{ __('Notes') }}</div>
            <div class="body">{!! nl2br(e($notes)) !!}</div>
        </div>
    @endif

    @if ($signatures)
        <table class="signatures">
            <tr>
                @foreach ($signatures as $signature)
                    <td>{{ $signature }}</td>
                    <td class="dots">......................................</td>
                @endforeach
            </tr>
        </table>
    @endif
</body>
</html>

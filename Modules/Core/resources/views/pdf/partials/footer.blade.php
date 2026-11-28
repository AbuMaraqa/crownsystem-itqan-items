{{-- ذيل كل صفحة: شكل الزاوية يمينا وخط رفيع حتى طرف الصفحة، ورقم الصفحة. --}}
@php
    $letterhead ??= \Modules\Core\Support\Letterhead::data();
@endphp
<htmlpagefooter name="doc-footer">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 24mm; vertical-align: bottom;">
                <img src="{{ $letterhead['corner'] }}" style="width: 22mm; height: 20mm;" alt="">
            </td>
            <td style="vertical-align: bottom; border-bottom: 0.6pt solid #b5b5b5; text-align: left; font-size: 7.5pt; color: #9a9a9a; padding-bottom: 1mm;">
                {PAGENO} / {nb}
            </td>
        </tr>
    </table>
</htmlpagefooter>

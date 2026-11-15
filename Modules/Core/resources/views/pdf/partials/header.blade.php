@php
    $pdfHeaderSettings = \Modules\Core\Entities\Settings::first();
    $pdfHeaderGeneralSettings = app(\Modules\Core\Settings\GeneralSettings::class);
    $pdfHeaderLogoUrl = $pdfHeaderSettings?->getLogoUrl();
@endphp
<htmlpageheader name="doc-header">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 25mm; text-align: right; vertical-align: middle;">
                @if ($pdfHeaderLogoUrl)
                    <img src="{{ $pdfHeaderLogoUrl }}" width="60" height="60" style="height: 18mm; width: 18mm;" alt="logo">
                @endif
            </td>
            <td style="text-align: right; vertical-align: middle; font-size: 15pt; font-weight: bold;">
                {{ $pdfHeaderGeneralSettings->site_name }}
            </td>
            <td style="width: 45mm; text-align: left; vertical-align: middle; font-size: 13pt; font-weight: bold;">
                {{ $docTitle ?? '' }}
            </td>
        </tr>
    </table>
    <div style="margin-top: 3mm; border-bottom: 1.2pt solid #0c456f;"></div>
</htmlpageheader>

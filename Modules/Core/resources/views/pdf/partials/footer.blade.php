@php
    $pdfFooterGeneralSettings = app(\Modules\Core\Settings\GeneralSettings::class);
@endphp
<htmlpagefooter name="doc-footer">
    <div style="border-top: 1px solid #e5e7eb; padding-top: 2mm; margin-top: 2mm; font-size: 8pt; color: #9ca3af; text-align: center;">
        {{ $pdfFooterGeneralSettings->site_name }} &middot; {{ now()->format('Y-m-d H:i') }} &middot; {{ __('Page') }} {PAGENO} / {nb}
    </div>
</htmlpagefooter>

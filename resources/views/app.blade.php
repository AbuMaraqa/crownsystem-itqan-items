@php
    $theme = app(\Modules\Core\Support\DashboardTheme::class);
    $faviconUrl = rescue(fn () => app(\Modules\Core\Settings\GeneralSettings::class)->site_logo_url, null, false);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locales::isRtl() ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link id="app-fonts" rel="stylesheet" href="{{ $theme->fontsUrl() }}">
        @if ($faviconUrl)
            <link rel="icon" href="{{ $faviconUrl }}">
        @endif
        {{-- نسخة مستقلة من Vite: صفحات Livewire تبقى على البناء الافتراضي (مهم مع Octane) --}}
        {{ (clone app(\Illuminate\Foundation\Vite::class))->useHotFile(public_path('admin.hot'))->useBuildDirectory('build-admin')->withEntryPoints(['resources/js/app.ts']) }}
        <style id="app-theme">{!! $theme->css() !!}</style>
        <script>try{if(localStorage.getItem('theme')==='dark')document.documentElement.classList.add('dark')}catch(e){}</script>
        @inertiaHead
    </head>
    <body class="dark:bg-gray-900">
        @inertia
    </body>
</html>

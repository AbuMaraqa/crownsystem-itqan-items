<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Modules\Core\Services\SidebarService;
use Modules\Core\Settings\GeneralSettings;
use Modules\Core\Support\DashboardTheme;
use Tighten\Ziggy\Ziggy;

/**
 * ما تحتاجه كل صفحة Inertia في لوحة التحكم: المستخدم وصلاحياته، القائمة الجانبية، النصوص،
 * اللغات، والمسارات (Ziggy).
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** اللغة جزء من النسخة: تبديلها يعيد تحميل الصفحة كاملة فلا تبقى خصائص once قديمة. */
    public function version(Request $request): ?string
    {
        return parent::version($request).'-'.app()->getLocale();
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => fn () => app()->getLocale(),
            'dir' => fn () => Locales::isRtl() ? 'rtl' : 'ltr',
            'locales' => fn () => Locales::forPage(),
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
            ],
            'translations' => Inertia::once(fn () => app('translator')->getLoader()->load(app()->getLocale(), '*', '*')),
            'ziggy' => Inertia::once(fn () => (new Ziggy)->toArray()),
            'auth' => fn () => $request->user() ? [
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'avatar' => $request->user()->avatar_url,
                ],
                'permissions' => $request->user()->getAllPermissions()->pluck('name'),
            ] : ['user' => null, 'permissions' => []],
            'sidebar' => fn () => $request->user() ? app(SidebarService::class)->all() : [],
            'store' => fn () => ['name' => rescue(fn () => app(GeneralSettings::class)->site_name, null, false) ?: config('app.name')],
            'dashboardTheme' => fn () => [
                'css' => app(DashboardTheme::class)->css(),
                'fontsUrl' => app(DashboardTheme::class)->fontsUrl(),
            ],
            'unreadNotifications' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'realtime' => fn () => Realtime::client($request->user()),
        ];
    }
}

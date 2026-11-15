<?php

namespace App\Support;

use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * لغات اللوحة كما يعرفها mcamara/laravel-localization، بالشكل الذي يقرؤه مبدّل اللغة في Vue.
 */
class Locales
{
    /** علم كل لغة (public/images/flags/<flag>.svg). */
    private const FLAGS = ['ar' => 'SA', 'en' => 'US', 'he' => 'IL'];

    /**
     * رموز اللغات المدعومة (ar, en ...).
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(LaravelLocalization::getSupportedLocales());
    }

    public static function isRtl(?string $locale = null): bool
    {
        $locale ??= app()->getLocale();

        return (LaravelLocalization::getSupportedLocales()[$locale]['script'] ?? null) === 'Arab'
            || in_array($locale, ['ar', 'he', 'fa', 'ur'], true);
    }

    /**
     * كل لغة مع رابط الصفحة الحالية بها.
     *
     * @return list<array{code: string, native: string, flag: string, dir: string, url: string}>
     */
    public static function forPage(): array
    {
        // صفحات الزوار (/login ...) بلا بادئة لغة: تُبدَّل اللغة بـ ?lang= (SetGuestLocale).
        $prefixed = in_array(request()->segment(1), self::keys(), true);

        return collect(LaravelLocalization::getSupportedLocales())
            ->map(fn (array $locale, string $code) => [
                'code' => $code,
                'native' => $locale['native'],
                'flag' => self::FLAGS[$code] ?? strtoupper($code),
                'dir' => self::isRtl($code) ? 'rtl' : 'ltr',
                'url' => $prefixed
                    ? LaravelLocalization::getLocalizedURL($code, null, [], true)
                    : request()->fullUrlWithQuery(['lang' => $code]),
            ])
            ->values()
            ->all();
    }
}

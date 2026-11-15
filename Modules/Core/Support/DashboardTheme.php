<?php

namespace Modules\Core\Support;

use Modules\Core\Settings\GeneralSettings;

/**
 * مظهر لوحة التحكم كما اختير في الاعدادات العامة: لون واحد يصبح سلم brand-* كاملاً، وخط واحد.
 */
final class DashboardTheme
{
    public const DEFAULT_COLOR = '#1570EF';

    /** نص الازرار ابيض دائماً، فاللون الافتح من هذا يجعلها غير مقروءة. */
    public const MINIMUM_CONTRAST = 3.0;

    public function __construct(private readonly GeneralSettings $settings) {}

    public static function isReadable(string $hex): bool
    {
        return BrandColor::isValid($hex) && BrandColor::fromHex($hex)->contrastRatio('#FFFFFF') >= self::MINIMUM_CONTRAST;
    }

    public function css(): string
    {
        $chosen = $this->setting('dashboard_color', self::DEFAULT_COLOR);
        $color = self::isReadable($chosen) ? $chosen : self::DEFAULT_COLOR;
        $shades = BrandColor::fromHex($color)->shades();

        $vars = ['--font-sans' => self::fonts()[$this->font()]['stack'], '--color-brand-25' => $shades[50], '--color-brand-950' => $shades[900]];

        foreach ($shades as $shade => $hex) {
            $vars["--color-brand-{$shade}"] = $hex;
        }

        return ':root{'.implode(';', array_map(fn ($name, $value) => "{$name}:{$value}", array_keys($vars), $vars)).'}';
    }

    public function fontsUrl(): string
    {
        return self::googleFontsUrl([self::fonts()[$this->font()]['family']]);
    }

    /**
     * كل خطوط اللوحة في رابط واحد، لمعاينتها في صفحة الاعدادات.
     */
    public static function previewUrl(): string
    {
        return self::googleFontsUrl(array_column(self::fonts(), 'family'));
    }

    /**
     * @param  list<string>  $families
     */
    private static function googleFontsUrl(array $families): string
    {
        return 'https://fonts.googleapis.com/css2?'.implode('&', array_map(fn (string $family) => 'family='.$family, $families)).'&display=swap';
    }

    /**
     * @return list<array{value: string, label: string, stack: string}>
     */
    public static function fontOptions(): array
    {
        return array_map(fn (string $key, array $font) => [
            'value' => $key,
            'label' => $font['name'],
            'stack' => $font['stack'],
        ], array_keys(self::fonts()), self::fonts());
    }

    /**
     * @return array<string, array{name: string, family: string, stack: string}>
     */
    public static function fonts(): array
    {
        return config('core.dashboard_fonts', []);
    }

    private function font(): string
    {
        $font = $this->setting('dashboard_font', '');

        return array_key_exists($font, self::fonts()) ? $font : array_key_first(self::fonts());
    }

    /**
     * قيمة من الاعدادات، او الافتراضية ان لم تُرحَّل الاعدادات بعد (قبل php artisan migrate).
     */
    private function setting(string $name, string $default): string
    {
        return rescue(fn () => $this->settings->{$name}, $default, false) ?: $default;
    }
}

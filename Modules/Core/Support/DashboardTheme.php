<?php

namespace Modules\Core\Support;

use Modules\Core\Settings\GeneralSettings;

/**
 * مظهر لوحة التحكم كما اختير في الاعدادات العامة: لون واحد يصبح سلم brand-* كاملاً، وخط واحد،
 * ولونا حدَّي المخزون الادنيين (--color-stock-min-1 / --color-stock-min-2) لنغمتي Badge
 * «stock-min-1» و«stock-min-2» اللتين يرسلهما StockStatus وStockAlertLevel.
 */
final class DashboardTheme
{
    public const DEFAULT_COLOR = '#1570EF';

    public const DEFAULT_STOCK_MIN_1_COLOR = '#F79009';

    public const DEFAULT_STOCK_MIN_2_COLOR = '#D92D20';

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

        $stock = $this->stockColors();
        $vars['--color-stock-min-1'] = $stock['min_1'];
        $vars['--color-stock-min-2'] = $stock['min_2'];

        return ':root{'.implode(';', array_map(fn ($name, $value) => "{$name}:{$value}", array_keys($vars), $vars)).'}';
    }

    /**
     * لون الرصيد عند الحد الادنى 1 او تحته (min_1) وعند الحد الادنى 2 او تحته (min_2)، للوحة وملفات PDF.
     *
     * @return array{min_1: string, min_2: string}
     */
    public function stockColors(): array
    {
        $color = function (string $name, string $default): string {
            $value = $this->setting($name, $default);

            return BrandColor::isValid($value) ? strtoupper($value) : $default;
        };

        return [
            'min_1' => $color('stock_min_1_color', self::DEFAULT_STOCK_MIN_1_COLOR),
            'min_2' => $color('stock_min_2_color', self::DEFAULT_STOCK_MIN_2_COLOR),
        ];
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

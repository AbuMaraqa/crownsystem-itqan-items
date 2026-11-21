<?php

namespace Modules\Items\Support\Itqan;

/**
 * توحيد كتابة أسماء إتقان قبل المقارنة: الهمزات (أ إ آ ← ا) والمسافات. أسماء
 * إتقان مكتوبة يدوياً، فـ«ألمنيوم» و«المنيوم» و«ثابت  مجرين» تعني الشيء نفسه.
 */
final class ItqanNames
{
    private const ALUMINUM_PREFIXES = ['المنيوم', 'الومنيوم'];

    public static function normalize(string $name): string
    {
        $name = str_replace(['أ', 'إ', 'آ'], 'ا', $name);

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    public static function isAluminum(string $name): bool
    {
        return self::aluminumRemainder($name) !== null;
    }

    /**
     * ما بعد كلمة «المنيوم» في الاسم الموحَّد، او null اذا لم يبدأ بها.
     */
    public static function aluminumRemainder(string $name): ?string
    {
        $name = self::normalize($name);

        foreach (self::ALUMINUM_PREFIXES as $prefix) {
            if ($name === $prefix) {
                return '';
            }

            if (str_starts_with($name, $prefix.' ')) {
                return trim(mb_substr($name, mb_strlen($prefix)));
            }
        }

        return null;
    }
}

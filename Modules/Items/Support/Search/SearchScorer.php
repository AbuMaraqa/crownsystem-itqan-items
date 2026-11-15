<?php

namespace Modules\Items\Support\Search;

/**
 * ترتيب المرشحين بالاقرب الى ما قصده المستخدم. الاوزان من الاقوى:
 * رقم الصنف او الباركود كما كُتب، ثم بدايته، ثم الاسم كما كُتب، ثم بدايته،
 * ثم كلمات البحث في الاسم، ثم في باقي المستند (التصنيف، العلامة...).
 * الاسم الاقصر يتقدم عند التساوي، والصنف غير النشط يتأخر.
 */
final class SearchScorer
{
    /**
     * @param  array<int, string|null>  $names  اسم الصنف بكل لغاته
     * @param  string|null  $document  نص الفهرس (search_text)
     */
    public static function score(SearchQuery $query, array $names, ?string $sku, ?string $barcode, ?string $document = null, bool $active = true): float
    {
        if ($query->isEmpty()) {
            return 0.0;
        }

        $score = self::codeScore($query->compact(), TextNormalizer::compact($sku), TextNormalizer::compact($barcode));

        $nameScore = null;
        $normalizedNames = [];

        foreach ($names as $name) {
            $normalized = TextNormalizer::normalize($name);

            if ($normalized !== '') {
                $normalizedNames[] = implode(' ', TextNormalizer::indexWords($normalized));
                $nameScore = max($nameScore ?? PHP_INT_MIN, self::nameScore($query, $normalized));
            }
        }

        $score += $nameScore ?? 0.0;

        // نسبة ما وُجد من كلمات البحث في المستند كله، وهي الفيصل في المطابقة الجزئية.
        $haystack = ' '.implode(' ', $normalizedNames).' '.TextNormalizer::normalize($sku).' '.TextNormalizer::normalize($barcode).' '.(string) $document.' ';
        $covered = 0;

        foreach ($query->terms as $term) {
            foreach ($query->variants($term) as $variant) {
                if (str_contains($haystack, ' '.$variant)) {
                    $covered++;
                    $score += 15;

                    continue 2;
                }
            }

            if (str_contains($haystack, $term)) {
                $covered += 0.5;
            }
        }

        $score += 200 * $covered / count($query->terms);

        if (! $active) {
            $score -= 40;
        }

        return $score;
    }

    private static function codeScore(string $query, string $sku, string $barcode): float
    {
        if ($query === '') {
            return 0.0;
        }

        return match (true) {
            $sku === $query => 1000.0,
            $barcode === $query => 950.0,
            $sku !== '' && str_starts_with($sku, $query) => 600.0,
            $barcode !== '' && str_starts_with($barcode, $query) => 550.0,
            default => 0.0,
        };
    }

    private static function nameScore(SearchQuery $query, string $name): float
    {
        $phrase = $query->normalized;

        $score = match (true) {
            $name === $phrase => 500.0,
            str_starts_with($name, $phrase) => 300.0,
            str_contains(' '.$name, ' '.$phrase) => 200.0,
            str_contains($name, $phrase) => 120.0,
            default => 0.0,
        };

        // كلمات الاسم مع صيغها بلا "ال" واجزاء رموزها، كما في الفهرس.
        $words = ' '.implode(' ', TextNormalizer::indexWords($name)).' ';
        $matched = 0;

        foreach ($query->terms as $term) {
            foreach ($query->variants($term) as $variant) {
                if (str_contains($words, ' '.$variant.' ')) {
                    $score += 80;
                    $matched++;

                    continue 2;
                }

                if (str_contains($words, ' '.$variant)) {
                    $score += 60;
                    $matched++;

                    continue 2;
                }
            }

            if (str_contains($name, $term)) {
                $score += 20;
            }
        }

        if ($matched === count($query->terms)) {
            $score += 100;
        }

        // الاسم الاقصر اقرب الى نص البحث عند التساوي.
        return $score - min(mb_strlen($name), 150) * 0.3;
    }
}

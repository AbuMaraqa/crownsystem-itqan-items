<?php

namespace Modules\Items\Support\Search;

use Illuminate\Support\HtmlString;
use Modules\Core\Support\Search\TextNormalizer;

/**
 * تمييز ما طابق البحث في النص المعروض بوسم <mark>. المطابقة على النص
 * الموحَّد (فـ "اسود" تُميّز "أسود")، والتمييز على النص الاصلي كما كُتب.
 */
final class SearchHighlighter
{
    private const ANYWHERE_MIN_LENGTH = 3;

    public static function highlight(?string $text, SearchQuery $query): HtmlString
    {
        $text = (string) $text;

        if ($text === '' || $query->isEmpty()) {
            return new HtmlString(e($text));
        }

        [$normalized, $offsets] = TextNormalizer::normalizeWithOffsets($text);
        $ranges = self::mergeRanges(self::matchRanges($normalized, $query));

        if ($ranges === []) {
            return new HtmlString(e($text));
        }

        $chars = mb_str_split($text);
        $html = '';
        $cursor = 0;

        foreach ($ranges as [$start, $end]) {
            $from = $offsets[$start];
            $to = $offsets[$end - 1] + 1;

            if ($from < $cursor) {
                continue;
            }

            $html .= e(implode('', array_slice($chars, $cursor, $from - $cursor)))
                .'<mark class="rounded-sm bg-amber-200/80 px-0.5 text-inherit dark:bg-amber-400/40">'
                .e(implode('', array_slice($chars, $from, $to - $from)))
                .'</mark>';
            $cursor = $to;
        }

        return new HtmlString($html.e(implode('', array_slice($chars, $cursor))));
    }

    /**
     * مواضع كل صيغة من كلمات البحث في النص الموحَّد.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function matchRanges(string $normalized, SearchQuery $query): array
    {
        $ranges = [];

        foreach ($query->terms as $term) {
            foreach ($query->variants($term) as $variant) {
                $length = mb_strlen($variant);
                $offset = 0;

                while (($position = mb_strpos($normalized, $variant, $offset)) !== false) {
                    // الكلمة القصيرة تُميَّز في بداية كلمة فقط، والا ملأ التمييز النص.
                    $atWordStart = $position === 0 || mb_substr($normalized, $position - 1, 1) === ' ';

                    if ($length >= self::ANYWHERE_MIN_LENGTH || $atWordStart) {
                        $ranges[] = [$position, $position + $length];
                    }

                    $offset = $position + $length;
                }
            }
        }

        return $ranges;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $ranges
     * @return array<int, array{0: int, 1: int}>
     */
    private static function mergeRanges(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);

        $merged = [];

        foreach ($ranges as $range) {
            $last = array_key_last($merged);

            if ($last !== null && $range[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }
}

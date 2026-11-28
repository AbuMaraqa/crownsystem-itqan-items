<?php

namespace Modules\Items\Support\Search;

use Modules\Core\Support\Search\TextNormalizer;

/**
 * نص البحث بعد تحليله: النص الاصلي، وصيغته الموحَّدة، وكلماته.
 *
 * الكلمات تُطابَق بأي ترتيب، وكل كلمة بادئة كلمة في المستند ("سبي" تطابق
 * "سبير")، والكلمة المعرَّفة تطابق صيغتها بلا "ال" ايضاً.
 */
final class SearchQuery
{
    public const MAX_LENGTH = 100;

    public const MAX_TERMS = 8;

    /**
     * @param  array<int, string>  $terms
     */
    private function __construct(
        public readonly string $raw,
        public readonly string $normalized,
        public readonly array $terms,
    ) {}

    public static function from(?string $search): self
    {
        $raw = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $search) ?? ''), 0, self::MAX_LENGTH);
        $terms = array_slice(TextNormalizer::words($raw), 0, self::MAX_TERMS);

        return new self($raw, implode(' ', $terms), $terms);
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /**
     * النص ملتصقاً بلا فواصل، لمقارنته برقم الصنف والباركود.
     */
    public function compact(): string
    {
        return str_replace(' ', '', $this->normalized);
    }

    /**
     * صيغ الكلمة التي تُقبل في المستند: الكلمة نفسها وصيغتها بلا "ال".
     *
     * @return array<int, string>
     */
    public function variants(string $term): array
    {
        $stripped = TextNormalizer::withoutArticle($term);

        return $stripped === null ? [$term] : [$term, $stripped];
    }

    /**
     * الكلمات التي يفهرسها FULLTEXT (لا يفهرس الكلمة الاقصر من $minLength).
     *
     * @return array<int, string>
     */
    public function longTerms(int $minLength): array
    {
        return array_values(array_filter($this->terms, fn (string $term): bool => mb_strlen($term) >= $minLength));
    }

    /**
     * @return array<int, string>
     */
    public function shortTerms(int $minLength): array
    {
        return array_values(array_filter($this->terms, fn (string $term): bool => mb_strlen($term) < $minLength));
    }

    /**
     * تعبير FULLTEXT بصيغة BOOLEAN MODE للكلمات الطويلة: كلها مطلوبة، او
     * ($requireAll = false) ايّها، فيُرتَّب المستند بعدد ما يطابق منها.
     */
    public function booleanFullText(int $minLength, bool $requireAll = true): string
    {
        $clauses = [];

        foreach ($this->longTerms($minLength) as $term) {
            $variants = array_map(fn (string $variant): string => $variant.'*', $this->variants($term));
            $clause = count($variants) > 1 ? '('.implode(' ', $variants).')' : $variants[0];
            $clauses[] = ($requireAll ? '+' : '').$clause;
        }

        return implode(' ', $clauses);
    }

    /**
     * انماط LIKE لكل كلمة على نص الفهرس (المحاط بمسافتين): بداية كلمة فيه،
     * او ($anywhere) اي موضع منه. كل عنصر بدائل لكلمة واحدة.
     *
     * @param  array<int, string>|null  $terms
     * @return array<int, array<int, string>>
     */
    public function likePatterns(bool $anywhere = false, ?array $terms = null): array
    {
        return array_map(
            fn (string $term): array => array_map(
                fn (string $variant): string => ($anywhere ? '%' : '% ').self::escapeLike($variant).'%',
                $this->variants($term)
            ),
            $terms ?? $this->terms
        );
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}

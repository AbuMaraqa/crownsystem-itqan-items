<?php

namespace Modules\Items\Support\Search;

/**
 * نتيجة البحث: معرّفات الاصناف مرتبةً بالاقرب، ووصف ما جرى للعرض.
 */
final class SearchResult
{
    /**
     * @param  array<int, int>  $ids
     * @param  bool  $approximate  لم يطابق شيء كل الكلمات، فعُرض ما طابق بعضها
     * @param  string|null  $correctedFrom  نص البحث الاصلي اذا صُحّحت لغة لوحة المفاتيح
     */
    public function __construct(
        public readonly SearchQuery $query,
        public readonly array $ids,
        public readonly bool $hasMore = false,
        public readonly bool $approximate = false,
        public readonly ?string $correctedFrom = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    public function count(): int
    {
        return count($this->ids);
    }

    public function correctedFrom(string $original): self
    {
        return new self($this->query, $this->ids, $this->hasMore, $this->approximate, $original);
    }
}

<?php

namespace Modules\Accounting\Support;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * مفتاح دفتر المخزون: الصنف (product_id) او مجموعة السعر (category_finish_id) التي تحمل رصيد
 * الكيلو وقيمته كما في اتقان. حركة او رصيد او طبقة او تكلفة يُكتب بأحدهما فقط.
 */
final class StockKey
{
    private function __construct(
        public readonly string $column,
        public readonly int $id,
    ) {}

    public static function product(int $productId): self
    {
        return new self('product_id', $productId);
    }

    public static function group(int $categoryFinishId): self
    {
        return new self('category_finish_id', $categoryFinishId);
    }

    /**
     * من بيانات حركة او من سجل فيه العمودان: مجموعة السعر اذا وُجدت، والا الصنف.
     *
     * @param  array<string, mixed>|Model  $source
     */
    public static function from(array|Model $source): self
    {
        $group = (int) data_get($source, 'category_finish_id');
        $product = (int) data_get($source, 'product_id');

        return match (true) {
            $group > 0 => self::group($group),
            $product > 0 => self::product($product),
            default => throw new DomainException(__('A stock movement needs an item or a price group.')),
        };
    }

    public function isGroup(): bool
    {
        return $this->column === 'category_finish_id';
    }

    /**
     * العمودان معاً، الآخر فارغ، لانشاء صف رصيد او حركة او طبقة.
     *
     * @return array{product_id: ?int, category_finish_id: ?int}
     */
    public function attributes(): array
    {
        return ['product_id' => $this->isGroup() ? null : $this->id, 'category_finish_id' => $this->isGroup() ? $this->id : null];
    }
}

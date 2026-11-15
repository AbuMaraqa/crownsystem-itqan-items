<?php

namespace Modules\Items\Support;

use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductUnit;
use Modules\Items\Entities\Unit;

/**
 * نتيجة قراءة باركود: الصنف، والوحدة (وحدة الباركود، والا الوحدة الاساسية)، وكمية
 * العبوة بتلك الوحدة، وكيف طابق: primary او exact او prefix.
 */
final class ResolvedBarcode
{
    public function __construct(
        public readonly Product $product,
        public readonly ?Unit $unit,
        public readonly ?ProductUnit $productUnit,
        public readonly string $packQuantity,
        public readonly string $matchedBy,
    ) {}
}

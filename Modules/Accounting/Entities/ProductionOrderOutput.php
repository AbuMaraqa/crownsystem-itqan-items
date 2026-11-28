<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Items\Entities\Product;

/**
 * صنف منتج في أمر الإنتاج (تبويب «الإنتاج» في اتقان). يدخل المخزن عند الترحيل بنصيبه من
 * التكلفة الكلية: بالوزن ان كانت كل الاصناف المنتجة موزونة، والا بالكمية (ProductionCost::allocate).
 */
class ProductionOrderOutput extends Model
{
    protected $fillable = [
        'production_order_id',
        'product_id',
        'description',
        'pricing_type',
        'quantity',
        'weight',
        'unit_cost',
        'total_cost',
        'notes',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'weight' => 'decimal:4',
        'unit_cost' => 'decimal:6',
        'total_cost' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'production_order_outputs');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

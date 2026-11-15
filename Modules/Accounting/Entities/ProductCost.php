<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Items\Entities\Product;

/**
 * متوسط التكلفة المرجح لصنف: قيمة المخزون ÷ كميته في كل المخازن، بالعملة الاساسية.
 */
class ProductCost extends Model
{
    protected $fillable = [
        'product_id',
        'quantity',
        'value',
        'average_cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'value' => 'decimal:4',
        'average_cost' => 'decimal:6',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'product_costs');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

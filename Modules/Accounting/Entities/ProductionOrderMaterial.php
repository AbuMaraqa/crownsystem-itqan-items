<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Items\Entities\Product;

/**
 * صنف مستهلك في أمر الإنتاج. الموزون يُكلَّف بوزنه × تكلفة الكغم، وغيره بكميته.
 */
class ProductionOrderMaterial extends Model
{
    protected $fillable = [
        'production_order_id',
        'sales_order_item_id',
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
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'production_order_materials');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

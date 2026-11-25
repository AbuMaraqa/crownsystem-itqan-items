<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * طبقة تكلفة FIFO: ما دخل من صنف في مخزن بكلفته، وما بقي منها.
 */
class StockCostLayer extends Model
{
    protected $fillable = [
        'product_id',
        'category_finish_id',
        'warehouse_id',
        'stock_movement_id',
        'sequence',
        'quantity',
        'remaining_quantity',
        'unit_cost',
        'remaining_value',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'stock_cost_layers');
    }
}

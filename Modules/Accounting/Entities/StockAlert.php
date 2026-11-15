<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Enums\StockAlertLevel;
use Modules\Items\Entities\Product;

/**
 * صنف داخل قسم متابعة المخزون بحالته الحالية. يكتبه StockAlertService فقط،
 * ويُحذف عند ارتفاع رصيد الصنف فوق الحد الادنى 1 او ايقاف مراقبته.
 */
class StockAlert extends Model
{
    protected $fillable = [
        'product_id',
        'level',
        'quantity',
        'entered_at',
        'level_changed_at',
    ];

    protected $casts = [
        'level' => StockAlertLevel::class,
        'quantity' => 'decimal:4',
        'entered_at' => 'datetime',
        'level_changed_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('accounting.table_prefix').'stock_alerts');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

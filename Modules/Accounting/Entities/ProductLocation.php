<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Enums\LocationDirection;
use Modules\Items\Entities\Product;

/**
 * مكان الصنف في المخزن. الموقع الاساسي هو الثابت الذي عليه بطاقة الرف، والاضافية
 * تُستعمل حين لا يتسع الاساسي.
 */
class ProductLocation extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'location_id',
        'is_primary',
        'direction',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'direction' => LocationDirection::class,
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'product_locations');
    }

    protected static function booted(): void
    {
        static::creating(fn (ProductLocation $productLocation) => $productLocation->created_by ??= auth()->id());
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }
}

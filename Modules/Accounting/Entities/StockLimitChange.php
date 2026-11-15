<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;

/**
 * سجل تعديل حدود صنف: من عدّل، ومتى، والقيم القديمة والجديدة. يكتبه StockLimitObserver.
 */
class StockLimitChange extends Model
{
    protected $fillable = [
        'product_id',
        'old_max_limit',
        'old_min_limit_1',
        'old_min_limit_2',
        'new_max_limit',
        'new_min_limit_1',
        'new_min_limit_2',
        'changed_by',
    ];

    protected $casts = [
        'old_max_limit' => 'decimal:4',
        'old_min_limit_1' => 'decimal:4',
        'old_min_limit_2' => 'decimal:4',
        'new_max_limit' => 'decimal:4',
        'new_min_limit_1' => 'decimal:4',
        'new_min_limit_2' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('accounting.table_prefix').'stock_limit_changes');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

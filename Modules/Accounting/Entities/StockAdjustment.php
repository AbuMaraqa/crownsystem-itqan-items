<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Entities\Concerns\IsInventoryDocument;
use Modules\Accounting\Enums\StockAdjustmentType;
use Modules\Core\Entities\Concerns\HasSearchIndex;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * رصيد افتتاحي، او جرد (العدد الفعلي مقابل رصيد النظام)، او تسوية مباشرة.
 */
class StockAdjustment extends Model
{
    use HasSearchIndex;
    use IsInventoryDocument;
    use LogsActivity;
    use SoftDeletes;

    /**
     * البحث في تسويات المخزون: بالرقم المرجعي والسبب والملاحظات.
     *
     * @return list<string>
     */
    public static function searchColumns(): array
    {
        return ['reference_no', 'reason', 'notes'];
    }

    protected $fillable = [
        'reference_no',
        'status',
        'type',
        'branch_id',
        'warehouse_id',
        'adjustment_date',
        'reason',
        'notes',
        'total_quantity',
        'total_weight',
        'total_cost',
        'journal_entry_id',
        'posted_at',
        'posted_by',
        'cancelled_at',
        'cancelled_by',
        'created_by',
    ];

    protected $casts = [
        'type' => StockAdjustmentType::class,
        'adjustment_date' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'stock_adjustments');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_id')->orderBy('sort_order');
    }
}

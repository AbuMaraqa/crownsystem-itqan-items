<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Entities\Concerns\IsInventoryDocument;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * تحويل مخزني بين مخزنين، او بين موقعين في نفس المخزن (حين يمتلئ موقع الصنف).
 */
class StockTransfer extends Model
{
    use IsInventoryDocument;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'reference_no',
        'status',
        'branch_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'transfer_date',
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
        'transfer_date' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'stock_transfers');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'stock_transfer_id')->orderBy('sort_order');
    }
}

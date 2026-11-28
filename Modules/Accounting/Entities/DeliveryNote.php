<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Entities\Concerns\IsInventoryDocument;
use Modules\Core\Entities\Concerns\HasSearchIndex;
use Modules\Core\Entities\User;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * سند تسليم: بضاعة طلبية بيع خرجت من المخزن الى الزبون.
 */
class DeliveryNote extends Model
{
    use HasSearchIndex;
    use IsInventoryDocument;
    use LogsActivity;
    use SoftDeletes;

    /**
     * البحث في سندات التسليم: بالرقم المرجعي واسم السائق ورقم المركبة واسم المستلم.
     *
     * @return list<string>
     */
    public static function searchColumns(): array
    {
        return ['reference_no', 'driver_name', 'vehicle_no', 'receiver_name'];
    }

    protected $fillable = [
        'reference_no',
        'status',
        'sales_order_id',
        'sales_invoice_id',
        'client_id',
        'branch_id',
        'warehouse_id',
        'delivery_date',
        'driver_name',
        'vehicle_no',
        'receiver_name',
        'delivery_address',
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
        'delivery_date' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'delivery_notes');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /**
     * فاتورة المبيعات التي انشأت السند تلقائياً عند ترحيلها (يُلغى معها).
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryNoteItem::class, 'delivery_note_id')->orderBy('sort_order');
    }
}

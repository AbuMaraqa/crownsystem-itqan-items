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
 * سند استلام: بضاعة طلبية شراء دخلت المخزن.
 */
class GoodsReceipt extends Model
{
    use HasSearchIndex;
    use IsInventoryDocument;
    use LogsActivity;
    use SoftDeletes;

    /**
     * البحث في سندات الاستلام: بالرقم المرجعي ورقم الحاوية ورقم مستند المورد.
     *
     * @return list<string>
     */
    public static function searchColumns(): array
    {
        return ['reference_no', 'container_no', 'supplier_document_no'];
    }

    protected $fillable = [
        'reference_no',
        'status',
        'purchase_order_id',
        'purchase_invoice_id',
        'client_id',
        'branch_id',
        'warehouse_id',
        'receipt_date',
        'supplier_document_no',
        'container_no',
        'received_by_name',
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
        'receipt_date' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'goods_receipts');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * فاتورة الشراء التي انشأت السند تلقائياً عند ترحيلها (يُلغى معها).
     */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
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
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id')->orderBy('sort_order');
    }
}

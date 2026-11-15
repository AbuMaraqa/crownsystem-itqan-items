<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * مادة احضرها العميل لتُقدَّم عليها خدمة (مثل خام ore). تبقى ملك العميل: لا سعر
 * لها ولا قيد ولا تدخل المخزون، وتُسجَّل للاثبات ولحساب كمية الخدمة بالوزن.
 */
class SalesOrderCustomerMaterial extends Model
{
    use LogsActivity;

    protected $fillable = [
        'sales_order_id',
        'product_id',
        'description',
        'quantity',
        'weight',
        'received_date',
        'notes',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'weight' => 'decimal:4',
        'received_date' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'sales_order_customer_materials');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(class_basename($this));
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

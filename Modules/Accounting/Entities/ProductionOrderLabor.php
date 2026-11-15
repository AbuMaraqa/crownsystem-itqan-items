<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;

/**
 * أجر عمل في أمر الإنتاج: العامل (مستخدم مسجل او اسم فقط) ونوع العمل، والكمية × الأجر.
 */
class ProductionOrderLabor extends Model
{
    protected $fillable = [
        'production_order_id',
        'worker_id',
        'worker_name',
        'description',
        'rate_basis',
        'quantity',
        'rate',
        'total_cost',
        'work_date',
        'notes',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'rate' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'work_date' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'production_order_labor');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }
}

<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند داخل اعادة التخمين وقيمته.
 */
class ConsolidatedPurchaseOrderReappraisalLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'reappraisal_id',
        'reappraisal_element_id',
        'amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_reappraisal_lines');
    }

    public function reappraisal(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderReappraisal::class, 'reappraisal_id');
    }

    /**
     * البند المحذوف حذفاً ناعماً يبقى ظاهراً في اعادات التخمين المحفوظة.
     */
    public function element(): BelongsTo
    {
        return $this->belongsTo(ReappraisalElement::class, 'reappraisal_element_id')->withTrashed();
    }
}

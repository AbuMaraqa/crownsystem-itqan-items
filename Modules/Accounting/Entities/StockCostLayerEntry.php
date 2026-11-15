<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * اثر حركة على طبقة: استهلاك كمية وقيمة، او اعادة تقييم. يُقرأ عند عكس الحركة فتعود الطبقة كما
 * كانت تماماً، ثم يُحذف.
 */
class StockCostLayerEntry extends Model
{
    public const ISSUE = 'issue';

    public const REVALUE = 'revalue';

    protected $fillable = [
        'stock_cost_layer_id',
        'stock_movement_id',
        'kind',
        'quantity',
        'value',
        'previous_unit_cost',
        'remaining_at_entry',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'stock_cost_layer_entries');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(StockCostLayer::class, 'stock_cost_layer_id');
    }
}

<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ServiceCostSource;

/**
 * خدمة في أمر الإنتاج (كالدهان): داخلية، او لدى جهة خارجية تُرسَل اليها القطع
 * وتعود، ولها رقم فاتورتها او سندها لدى الجهة.
 */
class ProductionOrderServiceLine extends Model
{
    protected $fillable = [
        'production_order_id',
        'product_id',
        'description',
        'cost_source',
        'service_provider_id',
        'provider_reference',
        'pricing_basis',
        'quantity',
        'unit_cost',
        'total_cost',
        'sent_date',
        'received_date',
        'notes',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'sent_date' => 'date',
        'received_date' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'production_order_services');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_provider_id');
    }

    public function isExternal(): bool
    {
        return $this->cost_source === ServiceCostSource::EXTERNAL->value;
    }
}

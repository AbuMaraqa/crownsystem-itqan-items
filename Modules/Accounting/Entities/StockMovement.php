<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;

/**
 * حركة مخزون واحدة: الكمية والوزن موجبان للدخول وسالبان للخروج، والكلفة كذلك.
 * لا تُعدَّل ولا تُحذف؛ الغاء المستند يضيف حركة عكسية.
 */
class StockMovement extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'location_id',
        'movement_date',
        'type',
        'quantity',
        'weight',
        'unit_cost',
        'total_cost',
        'sourceable_type',
        'sourceable_id',
        'source_line_id',
        'reference_no',
        'description',
        'created_by',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'type' => StockMovementType::class,
        'quantity' => 'decimal:4',
        'weight' => 'decimal:4',
        'unit_cost' => 'decimal:6',
        'total_cost' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'stock_movements');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * رابط المستند الذي انشأ الحركة (سند، فاتورة، مرتجع)، ان وُجدت له صفحة.
     */
    public function sourceUrl(): ?string
    {
        if (! $this->sourceable_id) {
            return null;
        }

        $route = match ($this->sourceable_type) {
            (new GoodsReceipt)->getMorphClass() => 'accounting.inventory.goods-receipts.edit',
            (new DeliveryNote)->getMorphClass() => 'accounting.inventory.delivery-notes.edit',
            (new StockTransfer)->getMorphClass() => 'accounting.inventory.transfers.edit',
            (new StockAdjustment)->getMorphClass() => 'accounting.inventory.adjustments.edit',
            (new SalesInvoice)->getMorphClass() => 'accounting.sales-invoices.edit',
            (new PurchaseInvoice)->getMorphClass() => 'accounting.purchase-invoices.edit',
            (new SalesReturn)->getMorphClass() => 'accounting.sales-returns.edit',
            (new PurchaseReturn)->getMorphClass() => 'accounting.purchase-returns.edit',
            default => null,
        };

        return $route ? route($route, $this->sourceable_id) : null;
    }
}

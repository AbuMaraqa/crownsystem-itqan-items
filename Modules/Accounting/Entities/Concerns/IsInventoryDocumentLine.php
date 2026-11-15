<?php

namespace Modules\Accounting\Entities\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Items\Entities\Product;

trait IsInventoryDocumentLine
{
    public function initializeIsInventoryDocumentLine(): void
    {
        $this->mergeCasts([
            'quantity' => 'decimal:4',
            'piece_length' => 'decimal:4',
            'weight' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:4',
        ]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

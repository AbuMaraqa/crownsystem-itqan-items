<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsolidatedPurchaseOrderClearanceAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'clearance_id',
        'attachment_type_id',
        'copy_path',
        'original_path',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_clearance_attachments');
    }

    public function clearance(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderClearance::class, 'clearance_id');
    }

    public function attachmentType(): BelongsTo
    {
        return $this->belongsTo(ClearanceAttachmentType::class, 'attachment_type_id');
    }
}

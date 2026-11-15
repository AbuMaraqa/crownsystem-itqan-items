<?php

namespace Modules\Accounting\Entities\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;

/**
 * ما تشترك فيه مستندات المخزن: الحالة، القيد، الحركات، ومن انشأ/رحّل/ألغى.
 */
trait IsInventoryDocument
{
    public function initializeIsInventoryDocument(): void
    {
        $this->mergeCasts([
            'status' => InventoryDocumentStatus::class,
            'total_quantity' => 'decimal:4',
            'total_weight' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(class_basename($this));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'sourceable');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isDraft(): bool
    {
        return $this->status === InventoryDocumentStatus::DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === InventoryDocumentStatus::POSTED;
    }

    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }
}

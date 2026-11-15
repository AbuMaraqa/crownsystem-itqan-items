<?php

namespace Modules\Items\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * باركود اضافي للصنف. pack_quantity: عدد ما يمثله من وحدته (وبلا وحدة: من الوحدة
 * الاساسية). is_prefix: يطابق كل ما يبدأ به. الكتابة عبر ProductBarcodeService.
 */
class ProductBarcode extends Model
{
    use LogsActivity;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix').'product_barcodes');
    }

    protected $fillable = [
        'product_id',
        'product_unit_id',
        'barcode',
        'pack_quantity',
        'is_prefix',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'pack_quantity' => 'decimal:6',
        'is_prefix' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "This model has been {$eventName} and value ")
            ->useLogName(class_basename($this));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

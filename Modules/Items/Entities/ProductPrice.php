<?php

namespace Modules\Items\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * سعر الصنف في قائمة اسعار، لوحدة او للوحدة الاساسية (product_unit_id فارغ).
 * الكتابة عبر ProductPriceService، والقراءة عبر PriceResolver (يحسب المعادلة).
 */
class ProductPrice extends Model
{
    use LogsActivity;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix').'product_prices');
    }

    protected $fillable = [
        'price_list_id',
        'product_id',
        'product_unit_id',
        'sell_price',
        'buy_price',
        'source_price_list_id',
        'source_factor',
        'created_by',
    ];

    protected $casts = [
        'sell_price' => 'decimal:4',
        'buy_price' => 'decimal:4',
        'source_factor' => 'decimal:6',
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

    protected static function booted(): void
    {
        parent::booted();

        // قائمة "شراء = بيع" تحفظ السعرين متساويين دائماً، من اي مسار كتابة.
        static::saving(function (self $price): void {
            if ($price->priceList?->same_buy_sell) {
                $price->buy_price = $price->sell_price;
            }
        });
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function sourcePriceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'source_price_list_id');
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

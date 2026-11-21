<?php

namespace Modules\Items\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;

/**
 * سعر الكيلو لمجموعة السعر (التصنيف × اللون) في قائمة سعر واحدة. أعمدته اعمدة
 * ProductPrice نفسها (بيع، شراء، قائمة مصدر ومعامل)، فتعمل القوائم المشتقة بالطريقة نفسها.
 */
class CategoryFinishPrice extends Model
{
    protected $fillable = [
        'category_finish_id',
        'price_list_id',
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

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix').'category_finish_prices');
    }

    public function categoryFinish(): BelongsTo
    {
        return $this->belongsTo(CategoryFinish::class, 'category_finish_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function sourcePriceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'source_price_list_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;

/**
 * صنف فرعي (قطع) داخل سطر صنف وزن في مستند: عدد القطع × طول القطعة × وزن المتر.
 * كل مستند له جدول قطعه؛ الصنف الوارث يحدد الجدول واعمدة الربط فقط.
 */
abstract class DocumentItemPiece extends Model
{
    /**
     * اسم الجدول بلا بادئة المحاسبة.
     */
    protected const TABLE = '';

    /**
     * اعمدة الصنف الوارث: سطر المستند، وقطعة المستند المصدر ان وُجد، وما يخصه (كالكلفة).
     *
     * @var array<int, string>
     */
    protected const COLUMNS = [];

    protected $casts = [
        'piece_count' => 'decimal:4',
        'piece_length' => 'decimal:4',
        'weight_per_meter' => 'decimal:4',
        'total_weight' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        $this->fillable = [
            ...static::COLUMNS,
            'product_id',
            'barcode',
            'description',
            'piece_count',
            'piece_length',
            'weight_per_meter',
            'total_weight',
            'sort_order',
            'created_by',
        ];

        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').static::TABLE);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

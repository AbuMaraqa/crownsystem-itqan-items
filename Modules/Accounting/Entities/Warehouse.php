<?php

namespace Modules\Accounting\Entities;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Concerns\HasSortOrder;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Warehouse extends Model implements TranslatableContract
{
    use HasFactory;
    use HasSortOrder;
    use LogsActivity;
    use SoftDeletes;
    use Translatable;

    public $translatedAttributes = [
        'name',
        'description',
        'address',
    ];

    public $useTranslationFallback = true;

    protected $fillable = [
        'code',
        'branch_id',
        'inventory_account_id',
        'phone',
        'email',
        'latitude',
        'longitude',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_active' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix') . 'warehouses');
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (Warehouse $warehouse) {
            if (! $warehouse->created_by) {
                $warehouse->created_by = auth()->id();
            }
        });

        static::created(function ($model) {
            $locale = app()->getLocale();
            $translationData = request()->only($model->translatedAttributes);

            if (! empty($translationData)) {
                $model->translateOrNew($locale)->fill($translationData);
                $model->save();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "This model has been {$eventName} and value ")
            ->useLogName(class_basename($this));
    }

    public function getAttributeName(): string
    {
        return (string) $this->name;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'warehouse_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class, 'warehouse_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'warehouse_id');
    }

    /**
     * حساب مخزون هذا المستودع في شجرة الحسابات.
     */
    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function options(): array
    {
        return static::query()
            ->with('translations')
            ->active()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn(Warehouse $warehouse) => [
                $warehouse->id => trim(($warehouse->code ? $warehouse->code . ' - ' : '') . $warehouse->name),
            ])
            ->toArray();
    }
}

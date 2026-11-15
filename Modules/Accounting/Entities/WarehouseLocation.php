<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * موقع تخزين: الممر (حرف) + رقم الموقع، مثل E10. الطابق/المنطقة اختياري للتجميع في الخريطة.
 */
class WarehouseLocation extends Model
{
    protected $fillable = [
        'warehouse_id',
        'zone',
        'aisle',
        'position',
        'code',
        'notes',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'warehouse_locations');
    }

    protected static function booted(): void
    {
        static::creating(function (WarehouseLocation $location) {
            $location->created_by ??= auth()->id();
            $location->code = $location->code ?: self::makeCode($location->aisle, $location->position);
        });
    }

    public static function makeCode(?string $aisle, ?int $position): string
    {
        return mb_strtoupper(trim((string) $aisle)).($position ? (string) $position : '');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function productLocations(): HasMany
    {
        return $this->hasMany(ProductLocation::class, 'location_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'location_id');
    }

    public function label(): string
    {
        return $this->zone ? $this->code.' · '.$this->zone : $this->code;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * ترتيب طبيعي: الممرات بحرف واحد قبل الحرفين (E قبل AA)، ثم رقم الموقع.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('zone')->orderByRaw('CHAR_LENGTH(aisle)')->orderBy('aisle')->orderBy('position');
    }

    public static function options(?int $warehouseId): array
    {
        if (! $warehouseId) {
            return [];
        }

        return static::query()
            ->where('warehouse_id', $warehouseId)
            ->active()
            ->ordered()
            ->get()
            ->mapWithKeys(fn (self $location) => [$location->id => $location->label()])
            ->toArray();
    }
}

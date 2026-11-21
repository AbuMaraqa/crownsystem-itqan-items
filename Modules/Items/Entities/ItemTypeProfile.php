<?php

namespace Modules\Items\Entities;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Entities\Concerns\HasSortOrder;
use Modules\Core\Entities\User;
use Modules\Items\Enums\ItemBonusMode;
use Modules\Items\Enums\ItemDateMode;
use Modules\Items\Enums\ItemSerialMode;
use Modules\Items\Enums\ItemSubItemMode;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * نوع الصنف: مصفوفة خصائص تحدد شكل سطر الصنف في المستندات. يُخزَّن في هذه
 * المرحلة فقط ولا يُطبَّق على السطور، ما عدا قاعدة الوزن لنوع الالمنيوم (Product).
 * منازل الكمية والسعر من 0 الى 5، والفارغ يعني ان الحقل مخفي.
 */
class ItemTypeProfile extends Model implements TranslatableContract
{
    use HasSortOrder;
    use LogsActivity;
    use Translatable;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix').'item_type_profiles');
    }

    protected $fillable = [
        'code',
        'quantity_decimals',
        'price_decimals',
        'has_color',
        'has_size',
        'notes_count',
        'date_mode',
        'bonus_mode',
        'serial_mode',
        'sub_item_mode',
        'dimensions',
        'sort_order',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'quantity_decimals' => 'integer',
        'price_decimals' => 'integer',
        'has_color' => 'boolean',
        'has_size' => 'boolean',
        'notes_count' => 'integer',
        'date_mode' => ItemDateMode::class,
        'bonus_mode' => ItemBonusMode::class,
        'serial_mode' => ItemSerialMode::class,
        'sub_item_mode' => ItemSubItemMode::class,
        'dimensions' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public $translatedAttributes = [
        'name',
    ];

    public $useTranslationFallback = true;

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

        // الحماية في النموذج نفسه، فلا يفرغ نوع الاصناف من اي مسار حذف.
        static::deleting(function (self $profile): void {
            if ($blocker = $profile->deletionBlocker()) {
                throw new DomainException($blocker);
            }
        });
    }

    /**
     * سبب منع الحذف، او null ان جاز.
     */
    public function deletionBlocker(): ?string
    {
        return Product::withTrashed()->where('item_type_profile_id', $this->id)->exists()
            ? __('This item type is used by items and cannot be deleted.')
            : null;
    }

    public function isAluminium(): bool
    {
        return $this->sub_item_mode === ItemSubItemMode::ALUMINIUM;
    }

    /**
     * قواعد الحقول الرقمية المحدودة، لنموذج الصفحة.
     *
     * @return array<string, array<int, string>>
     */
    public static function rangeRules(): array
    {
        return [
            'quantity_decimals' => ['nullable', 'integer', 'between:0,5'],
            'price_decimals' => ['nullable', 'integer', 'between:0,5'],
            'notes_count' => ['required', 'integer', 'between:0,3'],
            'dimensions' => ['required', 'integer', 'between:0,3'],
        ];
    }

    /**
     * الانواع النشطة لقائمة الاختيار في بطاقة الصنف، ومعها نوع الصنف الحالي ولو عُطّل.
     *
     * @return array<int, string>
     */
    public static function options(mixed $currentId = null): array
    {
        return static::query()
            ->with('translations')
            ->where(fn ($query) => $query->where('is_active', true)->when(filled($currentId), fn ($query) => $query->orWhere('id', $currentId)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (self $profile): array => [$profile->id => (string) $profile->name])
            ->all();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'item_type_profile_id');
    }
}

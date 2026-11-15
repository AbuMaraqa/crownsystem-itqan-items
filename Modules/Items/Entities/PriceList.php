<?php

namespace Modules\Items\Entities;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * قائمة اسعار: سعر بيع وشراء لكل صنف ووحدة. same_buy_sell يحفظ سعر الشراء مساوياً لسعر البيع.
 * PriceResolver هو طريق قراءة السعر، والطرف يرتبط بقائمته (price_list_id).
 */
class PriceList extends Model implements TranslatableContract
{
    use LogsActivity;
    use Translatable;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix').'price_lists');
    }

    protected $fillable = [
        'same_buy_sell',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'same_buy_sell' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
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

        static::deleting(function (self $list): void {
            if ($blocker = $list->deletionBlocker()) {
                throw new DomainException($blocker);
            }
        });

        // تحويل قائمة قائمة الى "شراء = بيع" يوحّد اسعارها المحفوظة فوراً.
        static::saved(function (self $list): void {
            if ($list->same_buy_sell && $list->wasChanged('same_buy_sell')) {
                $list->prices()->update(['buy_price' => DB::raw('sell_price')]);
            }
        });
    }

    /**
     * سبب منع الحذف، او null ان جاز: قائمة فيها اسعار، او تشتق منها قائمة اخرى، او
     * مربوطة بطرف او تصنيف زبائن.
     */
    public function deletionBlocker(): ?string
    {
        $inUse = $this->prices()->exists()
            || ProductPrice::query()->where('source_price_list_id', $this->id)->exists();

        if ($inUse) {
            return __('This price list has prices and cannot be deleted.');
        }

        $linked = DB::table('users')->where('price_list_id', $this->id)->exists()
            || DB::table(config('accounting.table_prefix').'customer_groups')->where('price_list_id', $this->id)->exists();

        return $linked ? __('This price list is linked to parties and cannot be deleted.') : null;
    }

    /**
     * القوائم المفعّلة لاختيارها في بطاقة الطرف وتصنيف الزبائن.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()
            ->with('translations')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (self $list): array => [(int) $list->id => (string) $list->name])
            ->all();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'price_list_id');
    }
}

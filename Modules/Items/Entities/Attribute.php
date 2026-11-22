<?php

namespace Modules\Items\Entities;

use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Entities\Concerns\HasSortOrder;
use Modules\Core\Entities\User;
use Modules\Items\Enums\AttributeType;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

// use Modules\Items\Database\Factories\AttributeFactory;

class Attribute extends Model implements TranslatableContract
{
    use HasSortOrder;
    use HasFactory;
    use LogsActivity;
    use Translatable;

    /**
     * The attributes that are mass assignable.
     */

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix') . 'attributes');
    }

    protected $fillable = [
        'slug',
        'type',
    ];

    protected $casts = [
        'status' => AttributeType::class,
    ];

    public $translatedAttributes = [
        'name',
        'description',
    ];

    public $useTranslationFallback = true;

    /**
     * سمة «اللون» النظامية: قيمها صورة من قائمة الألوان (Finish) يقرؤها المتجر، فلا تُعدَّل ولا
     * تُحذف من صفحة السمات؛ الألوان تُدار من قائمتها.
     */
    public function isSystem(): bool
    {
        return $this->slug === Finish::COLOUR_ATTRIBUTE;
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

    protected static function booted()
    {
        parent::booted();

        static::created(function ($model) {
            $locale = app()->getLocale();
            $translationData = request()->only($model->translatedAttributes);

            if (!empty($translationData)) {
                $model->translateOrNew($locale)->fill($translationData);
                $model->save();
            }
        });
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(AttributeValue::class , 'attribute_id');
    }
}

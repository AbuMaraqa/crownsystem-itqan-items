<?php

namespace Modules\Items\Entities;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Entities\Concerns\HasSortOrder;
use Modules\Core\Entities\User;
use Modules\Core\Enums\ImageQuality;
use Modules\Core\Enums\ImageSize;
use Modules\Core\Enums\ImageWidth;
use Modules\Core\Services\ImageService;
use Modules\Items\Enums\CategoryStatus;
use Modules\Items\Support\ItemCode;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

// use Modules\Items\Database\Factories\CategoryFactory;

class Category extends Model implements TranslatableContract, HasMedia
{
    use HasSortOrder;
    use HasFactory;
    use InteractsWithMedia;
    use Translatable;
    use SoftDeletes;
    use LogsActivity;

    /**
     * The attributes that are mass assignable.
     */

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix') . 'categories');
    }

    protected $fillable = [
        'id',
        'slug',
        'code',
        'status',
        'sort_order',
        'parent_id',
        'pricing_type',
        'created_by',
    ];

    protected $casts = [
        'status' => CategoryStatus::class,
    ];

    public $translatedAttributes = [
        'name',
        'description',
    ];

    public $useTranslationFallback = true;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->getFillable()) // تسجيل الحقول القابلة للتعبئة فقط
            ->logOnlyDirty() // تسجيل الحقول المُعدّلة فقط
            ->dontSubmitEmptyLogs() // عدم حفظ سجلات فارغة
            ->setDescriptionForEvent(fn(string $eventName) => "This model has been {$eventName} and value ")
            ->useLogName(class_basename($this));
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted()
    {
        parent::booted();

        // رقم التصنيف كما في شجرة النظام القديم: رقم ابيه وبعده تسلسل.
        static::creating(function (Category $category) {
            if (blank($category->code)) {
                $category->code = ItemCode::forCategory($category->parent_id);
            }
        });

        // التصنيف لا يكون اباً لنفسه ولا ابناً لاحد فروعه، وإلا انقطع من الشجرة ودارت حلقاتها بلا نهاية.
        static::saving(function (Category $category) {
            if (! $category->exists || ! $category->parent_id || ! $category->isDirty('parent_id')) {
                return;
            }

            $parents = static::query()->pluck('parent_id', 'id');
            $cursor = (int) $category->parent_id;
            $visited = [];

            while ($cursor && ! isset($visited[$cursor])) {
                if ($cursor === (int) $category->id) {
                    throw new DomainException(__('A category cannot be moved into one of its own subcategories.'));
                }

                $visited[$cursor] = true;
                $cursor = (int) ($parents[$cursor] ?? 0);
            }
        });

        static::created(function ($model) {
            $locale = app()->getLocale();
            $translationData = request()->only($model->translatedAttributes);

            if (!empty($translationData)) {
                $model->translateOrNew($locale)->fill($translationData);
                $model->save();
            }
        });
    }


    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /*************  ✨ Windsurf Command ⭐  *************/
    /*******  64b9d536-3e39-4f30-a055-43a64c9250cd  *******/
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')->where('status', CategoryStatus::ACTIVE);
    }

    public static function getTreeData(): array
    {
        return static::with(['children.childrenRecursive'])
            ->whereNull('parent_id')
            ->where('status', CategoryStatus::ACTIVE)
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'label' => $category->name,
                    'children' => static::mapChildrenToTree($category->children)
                ];
            })
            ->toArray();
    }

    private static function mapChildrenToTree($children): array
    {
        return $children->map(function ($child) {
            return [
                'id' => $child->id,
                'label' => $child->name,
                'children' => static::mapChildrenToTree($child->children)
            ];
        })->toArray();
    }

    public function allChildren()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    // الحصول على جميع الأطفال والأحفاد بشكل متداخل
    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('category')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    public function addImage($file)
    {
        if (is_array($file)) {
            $file = reset($file);
        }

        // التحقق من نوع الملف
        if (! $file instanceof \Illuminate\Http\UploadedFile) {
            return null;
        }

        // مسح الصورة السابقة
        $this->clearMediaCollection('category');

        try {
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $fileName = time() . '_' . Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $extension;

            $media = $this
                ->addMedia($file)
                ->usingFileName($fileName)
                ->toMediaCollection('category', 'items');

            $size = ImageSize::MEDIUM;

            ImageService::optimize($media->getPath(), ImageQuality::HIGH->value);
            ImageService::resize($media->getPath(), $size->width(), $size->height());

            return $media;
        } catch (\Exception $e) {
            \Log::error('Error uploading category image: ' . $e->getMessage());
            return null;
        }
    }

    public function getImageAttribute()
    {
        return $this->getFirstMediaUrl('category');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, config('items.table_prefix') . 'products_categories');
    }

    /**
     * ألوان التصنيف مع سعر الكيلو لكل منها (مجموعات السعر).
     */
    public function categoryFinishes(): HasMany
    {
        return $this->hasMany(CategoryFinish::class, 'category_id');
    }

    public static function getCategoryTree($parentId = null)
    {
        return Category::where('parent_id', $parentId)
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'children' => self::getCategoryTree($category->id),
                ];
            })
            ->toArray();
    }

    public function offers(): MorphMany
    {
        return $this->morphMany(Offer::class, 'offerable');
    }
}

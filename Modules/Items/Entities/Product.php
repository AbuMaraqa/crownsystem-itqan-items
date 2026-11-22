<?php

namespace Modules\Items\Entities;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\User;
use Modules\Core\Enums\ImageQuality;
use Modules\Core\Enums\ImageSize;
use Modules\Core\Enums\ImageWidth;
use Modules\Core\Services\ImageService;
use Modules\Items\Enums\CategoryStatus;
use Modules\Items\Enums\ItemType;
use Modules\Items\Enums\ProductStatus;
use Modules\Items\Enums\ProfileThickness;
use Modules\Items\Enums\ServiceKind;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

// use Modules\Items\Database\Factories\ProductFactory;

class Product extends Model implements TranslatableContract, HasMedia
{
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

        $this->setTable(config('items.table_prefix') . 'products');
    }

    protected $fillable = [
        'id',
        'slug',
        'barcode',
        'sku',
        'discount',
        'base_price',
        'sale_price',
        'cost_price',
        'wholesale_price',
        // stock_quantity و stock_weight ليسا هنا عمداً: هما نسخة من رصيد دفتر المخزون
        // يكتبها StockLedgerService وحده، فلا تكتب فوقهما شاشة الصنف ولا الـ API ولا الاستيراد.
        'manage_stock',
        'low_stock_threshold',
        'stock_status',
        'allow_backorder',
        'max_quantity_per_order',
        'discount_type',
        'discount_value',
        'discount_start_date',
        'discount_end_date',
        'min_quantity_discount',
        'weight',
        'weight_per_meter',
        'length',
        'bundle_count',
        'profile_thickness',
        'is_open_profile',
        'area',
        'pricing_type',
        'item_type',
        'item_type_profile_id',
        'service_kind',
        'service_cost_source',
        'revenue_account_id',
        'cost_account_id',
        'status',
        'is_featured',
        'is_digital',
        'meta_data',
        'parent_id',
        'main_product_id',
        'finish_id',
        'category_finish_id',
        'synonym_product_id',
        'brand_id',
        'unit_id',
        'min_limit_1',
        'min_limit_2',
        'max_limit',
        'shelf',
        'color',
        'site_area',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'status' => ProductStatus::class,
        'meta_data' => 'array',
        'profile_thickness' => ProfileThickness::class,
        'is_open_profile' => 'boolean',
    ];

    public $translatedAttributes = [
        'name',
        'description',
        'short_description',
    ];

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

        static::creating(function ($model) {
            // تعيين SKU قبل الحفظ
            if (empty($model->sku)) {
                $model->sku = self::generateUniqueSku();
            }
        });

        static::saving(fn (self $product) => $product->applyItemTypeProfileRules());

        static::created(function ($model) {
            $locale = app()->getLocale();
            $translationData = request()->only($model->translatedAttributes);

            if (!empty($translationData)) {
                $model->translateOrNew($locale)->fill($translationData);
                $model->save();
            }
        });
    }

    /**
     * القاعدة الوحيدة لنوع الصنف على الصنف نفسه: نوع الالمنيوم يُسعَّر بالوزن. مكانها
     * هنا فتسري من كل الشاشات والـ API. تعديل النوع لاحقاً لا يغيّر اصنافه القائمة.
     */
    private function applyItemTypeProfileRules(): void
    {
        if (blank($this->item_type_profile_id) || ! $this->isDirty(['item_type_profile_id', 'pricing_type'])) {
            return;
        }

        if (ItemTypeProfile::query()->find($this->item_type_profile_id)?->isAluminium()) {
            $this->pricing_type = 'weight';
        }
    }

    public function registerAllMediaConversions(): void
    {
        $this->addMediaCollection('product')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

        $this->addMediaCollection('product_gallery')

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
        $this->clearMediaCollection('product');

        try {
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $fileName = time() . '_' . Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $extension;

            $media = $this
                ->addMedia($file)
                ->usingFileName($fileName)
                ->toMediaCollection('product', 'items');

            $size = ImageSize::MEDIUM;

            ImageService::optimize($media->getPath() , ImageQuality::HIGH->value);
            ImageService::resize($media->getPath() , $size->width(), $size->height());

            return $media;
        } catch (\Exception $e) {
            \Log::error('Error uploading product image: ' . $e->getMessage());
            return null;
        }
    }

    public function addGalleryImage($files)
    {
        // التأكد من أن المدخل هو مصفوفة
        if (!is_array($files)) {
            $files = [$files];
        }

        try {
            foreach ($files as $file) {
                // التحقق من نوع الملف
                if (! $file instanceof \Illuminate\Http\UploadedFile) {
                    continue;
                }

                $originalName = $file->getClientOriginalName();
                $extension = $file->getClientOriginalExtension();
                $fileName = time() . '_' . uniqid() . '_' . Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $extension;

                $media = $this
                    ->addMedia($file)
                    ->usingFileName($fileName)
                    ->toMediaCollection('product_gallery', 'items');

                // تحسين وتغيير حجم الصورة
                $size = ImageSize::MEDIUM;
                ImageService::optimize($media->getPath(), ImageQuality::HIGH->value);
                ImageService::resize($media->getPath(), $size->width(), $size->height());
            }

            return true;
        } catch (\Exception $e) {
            \Log::error('Error uploading product gallery images: ' . $e->getMessage());
            return null;
        }
    }

    public function getGalleryAttribute()
    {
        // للـ API: إرجاع مصفوفة URLs
        if (request()->wantsJson() || request()->is('api/*')) {
            return $this->getMedia('product_gallery')->map(function ($media) {
                return $media->getUrl();
            });
        }

        // للـ Filament Form: إرجاع null لأن Filament يتعامل مع Media مباشرة
        return null;
    }

    public function getImageAttribute()
    {
        return $this->getFirstMediaUrl('product');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, config('items.table_prefix') . 'products_categories');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

//    public function attributeValue(): BelongsToMany
//    {
//        return $this->belongsToMany(AttributeValue::class, config('items.table_prefix') . 'product_variations', 'product_id', 'attribute_value_id');
//    }

    public function variations(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id');
    }

    /**
     * سبب منع حذف الصنف، او null إن جاز حذفه. الصنف الرئيسي لا يُحذف وتحته اصناف فرعية،
     * والصنف المستخدم في مستند لا يُحذف لان بنده يفقد صنفه ثم يسقط عند حفظ المستند.
     */
    public function deletionBlocker(): ?string
    {
        if (static::withTrashed()->where('main_product_id', $this->id)->exists()) {
            return __('Remove the sub-items of this main item first.');
        }

        return $this->hasDocumentLines() ? __('This item is used in documents and cannot be deleted.') : null;
    }

    /**
     * هل للصنف سطر في مستند (رئيسي او قطعة) او حركة مخزون؟
     */
    public function hasDocumentLines(): bool
    {
        $prefix = config('accounting.table_prefix');

        if ($prefix === null) {
            return false;
        }

        $documentLineTables = [
            'purchase_order_items', 'purchase_order_item_pieces',
            'sales_order_items', 'sales_order_item_pieces', 'sales_order_customer_materials',
            'consolidated_purchase_order_items', 'consolidated_purchase_order_item_pieces',
            'sales_quotation_items', 'sales_quotation_item_pieces',
            'purchase_quotation_items',
            'sales_invoice_items', 'sales_invoice_item_pieces',
            'purchase_invoice_items', 'purchase_invoice_item_pieces',
            'sales_return_items', 'sales_return_item_pieces',
            'purchase_return_items', 'purchase_return_item_pieces',
            'stock_movements',
        ];

        foreach ($documentLineTables as $table) {
            if (Schema::hasTable($prefix.$table) && Schema::hasColumn($prefix.$table, 'product_id') && DB::table($prefix.$table)->where('product_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * الخدمة لا تدخل المخزون، وتُباع في قسم الخدمات بالطلبية والفاتورة.
     */
    public function isService(): bool
    {
        return $this->item_type === ItemType::SERVICE->value;
    }

    public function isTransportService(): bool
    {
        return $this->isService() && $this->service_kind === ServiceKind::TRANSPORT->value;
    }

    /**
     * لون الصنف الابن (او «بلا لون» للموزون الذي لا ألوان له).
     */
    public function finish(): BelongsTo
    {
        return $this->belongsTo(Finish::class, 'finish_id');
    }

    /**
     * مجموعة السعر (التصنيف × اللون) التي يُقرأ منها سعر الكيلو لهذا المتغيّر.
     */
    public function categoryFinish(): BelongsTo
    {
        return $this->belongsTo(CategoryFinish::class, 'category_finish_id');
    }

    /**
     * الصنف الرئيسي لصنف الوزن الفرعي، وعليه سعر الكيلوغرام.
     */
    public function mainProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'main_product_id');
    }

    /**
     * لون الصنف: لونه ان كُتب، والا لون نوع الالمنيوم (الصنف الرئيسي) الذي يتبعه.
     */
    public function getEffectiveColorAttribute(): ?string
    {
        return filled($this->color) ? $this->color : ($this->mainProduct?->color ?: null);
    }

    /**
     * الالوان المستخدمة في الاصناف، لاقتراحها عند الكتابة فتبقى التسمية موحدة.
     *
     * @return array<int, string>
     */
    public static function colorOptions(): array
    {
        return static::query()->whereNotNull('color')->where('color', '!=', '')->distinct()->orderBy('color')->pluck('color')->all();
    }

    /**
     * الاصناف الفرعية (المقاطع) المندرجة تحت هذا الصنف الرئيسي.
     */
    public function subProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'main_product_id');
    }

    /**
     * الصنف المرادف (تبويب معلومات في بطاقة الصنف).
     */
    public function synonymProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'synonym_product_id');
    }

    /**
     * موردو الصنف: مستخدمون بدور Supplier.
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, config('items.table_prefix') . 'products_suppliers', 'product_id', 'supplier_id')
            ->withTimestamps();
    }

    /*************  ✨ Windsurf Command ⭐  *************/
    /**
     * Generate a unique SKU
     *
     * @return string
     */
    /*******  650b6af4-fb5c-48f4-87f1-be182d251b22  *******/
    /**
     * رابط فريد من الاسم. اسم المقطع يتكرر بين الالوان وداخل الصنف الرئيسي، فيُضاف رقم
     * بدل خطأ التكرار. المحذوف يُعدّ لان فهرس الرابط يشمله.
     */
    public static function uniqueSlug(?string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug((string) $name) ?: 'item';
        $slug = $base;
        $counter = 1;

        while (static::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    public static function generateUniqueSku()
    {
        do {
            $sku = 'SKU-' . strtoupper(Str::random(8));
        } while (self::where('sku', $sku)->exists());

        return $sku;
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, config('items.table_prefix') . 'products_tags' , 'product_id', 'tag_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, config('items.table_prefix') . 'products_labels');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function itemTypeProfile(): BelongsTo
    {
        return $this->belongsTo(ItemTypeProfile::class, 'item_type_profile_id');
    }

    /**
     * الوحدات المكافئة، بلا الوحدة الاساسية (unit_id) التي معاملها 1.
     */
    public function productUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class, 'product_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * الباركودات الاضافية، بلا الباركود الرئيسي (barcode).
     */
    public function productBarcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'product_id')->orderBy('sort_order')->orderBy('id');
    }

    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'product_id');
    }

    /**
     * Cutting recipes where this product is the raw source (i.e. what can be cut from it).
     */
    public function cuttingRecipesAsSource(): HasMany
    {
        return $this->hasMany(ProductCuttingRecipe::class, 'source_product_id');
    }

    /**
     * Cutting recipes where this product is the finished/output item.
     */
    public function cuttingRecipesAsOutput(): HasMany
    {
        return $this->hasMany(ProductCuttingRecipe::class, 'output_product_id');
    }

    /**
     * ملحقات الصنف: اصناف تُقترح معه عند البيع، بترتيب عرضها.
     */
    public function accessories(): HasMany
    {
        return $this->hasMany(ProductAccessory::class, 'product_id')->orderBy('sort_order')->orderBy('id');
    }

    public function manufacturers(): BelongsToMany
    {
        return $this->belongsToMany(Manufacturer::class, config('items.table_prefix') . 'products_manufacturers');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(AttributeValue::class, 'attribute_id');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, config('items.table_prefix') . 'product_attribute_value', 'product_id', 'attribute_id');
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, config('items.table_prefix') . 'addon_product', 'product_id', 'addon_id')
            ->withPivot([
                'id',
                'is_required',
                'min_selection',
                'max_selection',
                'max_quantity_per_option',
                'created_by'
            ])
            ->withTimestamps();
    }

    public function addonOptions()
    {
        return $this->hasMany(config('items.table_prefix') . 'product_addon_options', 'addon_product_id', 'id')
            ->withPivot([
                'id',
                'price',
                'is_default',
            ]);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, config('items.table_prefix') . 'branch_product', 'product_id', 'branch_id');
    }

    public function offers(): MorphMany
    {
        return $this->morphMany(Offer::class, 'offerable');
    }

    public function activeOffers(): MorphMany
    {
        return $this->offers()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_date')
                  ->orWhere('start_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now());
            })
            ->orderBy('created_at', 'desc');
    }


    public function branchProducts(): HasMany
    {
        return $this->hasMany(BranchPrice::class, 'product_id');
    }

    public function scopeWithBranchPrices($query, $branchId)
    {
        $query->with(['branchProducts' => function ($q) use ($branchId) {
            $q->where('branch_id', $branchId);
        }]);
    }

    /**
     * الاصناف الرئيسية (انواع الالمنيوم): لها اصناف فرعية مرتبطة بها، ورصيدها مجموع رصيد فروعها.
     */
    public function scopeMainItems($query)
    {
        return $query->whereIn($query->qualifyColumn('id'), static::query()->select('main_product_id')->whereNotNull('main_product_id'));
    }

    /**
     * كل الاصناف ما عدا الرئيسية: الاصناف التي يُرحَّل عليها مخزون فعلاً.
     */
    public function scopeWithoutMainItems($query)
    {
        return $query->whereNotIn($query->qualifyColumn('id'), static::query()->select('main_product_id')->whereNotNull('main_product_id'));
    }

    /**
     * السلع التي وصل رصيدها الى حد اعادة الطلب (الحد الادنى 1) او اقل منه.
     * نفس قاعدة حالة المخزون في شاشة الارصدة (StockStatus)، والصنف الرئيسي يُستثنى
     * لان رصيده مجموع فروعه وكل فرع يُنبَّه عنه وحده.
     */
    public function scopeLowStock($query)
    {
        return $query->where($query->qualifyColumn('item_type'), '!=', ItemType::SERVICE->value)
            ->where($query->qualifyColumn('min_limit_1'), '>', 0)
            ->whereColumn($query->qualifyColumn('stock_quantity'), '<=', $query->qualifyColumn('min_limit_1'))
            ->withoutMainItems();
    }

    public function getPriceAttribute()
    {
        if ($this->relationLoaded('branchProducts') && $this->branchProducts->isNotEmpty()) {
            return $this->branchProducts->first()->price;
        }

        return $this->base_price;
    }

    public function getDiscountablePrice(): float
    {
        return $this->price;
    }
}

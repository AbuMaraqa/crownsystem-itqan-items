<?php

namespace Modules\Items\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Support\StockLimits;
use Modules\Branch\Entities\Branch;
use Modules\Items\Entities\Addon;
use Modules\Items\Entities\AddonOption;
use Modules\Items\Entities\AttributeValue;
use Modules\Items\Entities\Brand;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Entities\Manufacturer;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\Unit;
use Modules\Items\Enums\ItemType;
use Modules\Items\Enums\ProfileThickness;
use Modules\Items\Enums\ServiceCostSource;
use Modules\Items\Enums\ServiceKind;
use Modules\Items\Support\ItemCardRules;

/**
 * بطاقة الصنف (اضافة وتعديل). الصفحة ترسل كل شيء عدا الملفات حقلاً واحداً بصيغة JSON اسمه
 * `payload`: النموذج كبير فيتجاوز max_input_vars فتسقط آخر حقوله بصمت، و FormData لا تحمل
 * قائمة فارغة ولا null. الملفات حقول عادية بنفس مسارات النموذج (`image`، `gallery.0`،
 * `variations.2.image`) فتعود اخطاؤها الى حقولها.
 *
 * البيانات لا تُدمج في الطلب نفسه: نموذج الصنف يقرأ الاسم من request() عند الانشاء
 * (Product::created)، فلو وصل الاسم في الطلب لكُتب اسم الصنف على متغيراته.
 */
class ProductRequest extends FormRequest
{
    private const IMAGE = ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:5000'];

    private const MONEY = ['nullable', 'numeric', 'min:0', 'max:99999999.99']; // decimal(10, 2)

    private const LIMIT = ['nullable', 'numeric', 'min:0', 'max:99999999.99']; // decimal(10, 2)

    private const MEASURE = ['nullable', 'numeric', 'min:0', 'max:999999.9999']; // decimal(10, 4)

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->payload ??= $this->buildPayload();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->productId();
        $table = (new Product)->getTable();
        $data = $this->validationData();
        $isWeight = ($data['pricing_type'] ?? null) === 'weight';

        return [
            ...$this->allowedOptionRules(),
            ...$this->variationRowRules($id, $table),
            'name' => ['required', 'string', 'max:255'],
            // الفهرس الفريد يشمل المحذوف.
            'slug' => ['nullable', 'string', 'max:255', Rule::unique($table, 'slug')->ignore($id)],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique($table, 'sku')->ignore($id)],
            // الباركود يتكرر بين الاصناف (المقطع نفسه تحت كل لون).
            'barcode' => ['nullable', 'string', 'max:255'],
            // التصنيف مطلوب عند التعديل فقط، كما كان.
            'category_id' => $id ? ['required', 'array', 'min:1'] : ['array'],
            'category_id.*' => ['integer', Rule::exists((new Category)->getTable(), 'id')],
            'tags' => ['array'],
            'tags.*' => ['required', 'string', 'max:255'],
            'labels' => ['array'],
            'labels.*' => ['required', 'string', 'max:255'],
            'brand_id' => ['nullable', 'integer', Rule::exists((new Brand)->getTable(), 'id')],
            'unit_id' => ['nullable', 'integer', Rule::exists((new Unit)->getTable(), 'id')],
            'manufacturers' => ['array'],
            'manufacturers.*' => ['integer', Rule::exists((new Manufacturer)->getTable(), 'id')],
            'branches' => ['array'],
            'branches.*' => ['integer', Rule::exists((new Branch)->getTable(), 'id')],
            'short_description' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'weight_per_meter' => self::MEASURE,
            'length' => self::MEASURE,
            'site_area' => self::LIMIT,
            'pricing_type' => ['required', Rule::in(['simple', 'weight']), $this->closure(fn ($value) => ItemCardRules::weightPricingError($value, $id ?: null))],
            'item_type_profile_id' => ['nullable', 'integer', Rule::exists((new ItemTypeProfile)->getTable(), 'id')],
            'bundle_count' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'profile_thickness' => ['nullable', Rule::enum(ProfileThickness::class)],
            'is_open_profile' => ['nullable', Rule::in([0, 1, '0', '1', true, false])],
            'area' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'item_type' => ['required', Rule::in(array_column(ItemType::cases(), 'value'))],
            'service_kind' => ['nullable', Rule::in(array_column(ServiceKind::cases(), 'value'))],
            'service_cost_source' => ['nullable', Rule::in(array_column(ServiceCostSource::cases(), 'value'))],
            'revenue_account_id' => ['nullable', 'integer', Rule::exists((new Account)->getTable(), 'id')],
            'cost_account_id' => ['nullable', 'integer', Rule::exists((new Account)->getTable(), 'id')],
            'main_product_id' => ['nullable', 'integer', $this->closure(fn ($value) => ($data['item_type'] ?? null) === ItemType::SERVICE->value
                ? null
                : ItemCardRules::mainProductError($value, $data['pricing_type'] ?? null, $id ?: null))],
            // صنف الوزن بلا سعر في بطاقته.
            'base_price' => [$isWeight ? 'nullable' : 'required', ...array_slice(self::MONEY, 1)],
            'sale_price' => self::MONEY,
            'cost_price' => self::MONEY,
            'wholesale_price' => self::MONEY,
            'discount_type' => ['nullable', Rule::in(['none', 'percentage', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'discount_start_date' => ['nullable', 'date'],
            'discount_end_date' => ['nullable', 'date'],
            'color' => ['nullable', 'string', 'max:50'],
            'max_limit' => self::LIMIT,
            'min_limit_1' => self::LIMIT,
            'min_limit_2' => self::LIMIT,
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', ...self::IMAGE],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => self::IMAGE,
            'removed_image' => ['nullable', 'array'],
            'removed_image.*' => ['integer'],
            'removed_gallery' => ['nullable', 'array'],
            'removed_gallery.*' => ['integer'],
            'accessories' => ['array'],
            'accessories.*.accessory_product_id' => ['required', 'integer', 'distinct', Rule::exists($table, 'id'), $this->closure(
                fn ($value) => $id && (int) $value === $id ? __('An item cannot be an accessory of itself.') : null
            )],
            'accessories.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:99999999.9999'],
            'cutting_recipes' => ['array'],
            'cutting_recipes.*.id' => ['nullable', 'integer'],
            'cutting_recipes.*.output_product_id' => ['required', 'integer', 'distinct', Rule::exists($table, 'id')],
            'cutting_recipes.*.pieces_per_unit' => ['required', 'numeric', 'min:0', 'max:99999999.9999'],
            'addons' => ['array'],
            'addons.*.addon_id' => ['required', 'integer', 'distinct', Rule::exists((new Addon)->getTable(), 'id')],
            'addons.*.is_required' => ['boolean'],
            'addons.*.min_selection' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'addons.*.max_selection' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'addons.*.max_quantity_per_option' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'addons.*.allowed_options' => ['array'],
            'addons.*.allowed_options.*.addon_option_id' => ['required', 'integer', 'distinct'],
            'addons.*.allowed_options.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'addons.*.allowed_options.*.is_default' => ['boolean'],
            'variations' => ['array'],
            'variations.*.name' => ['required', 'string', 'max:255'],
            'variations.*.short_description' => ['nullable', 'string'],
            'variations.*.description' => ['nullable', 'string'],
            'variations.*.barcode' => ['nullable', 'string', 'max:255'],
            'variations.*.base_price' => ['required', ...array_slice(self::MONEY, 1)],
            'variations.*.sale_price' => self::MONEY,
            'variations.*.cost_price' => self::MONEY,
            'variations.*.wholesale_price' => self::MONEY,
            'variations.*.discount_type' => ['nullable', Rule::in(['none', 'percentage', 'fixed'])],
            'variations.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'variations.*.discount_start_date' => ['nullable', 'date'],
            'variations.*.discount_end_date' => ['nullable', 'date'],
            'variations.*.max_limit' => self::LIMIT,
            'variations.*.min_limit_1' => self::LIMIT,
            'variations.*.min_limit_2' => self::LIMIT,
            'variations.*.category_id' => ['nullable', 'array'],
            'variations.*.category_id.*' => ['integer', Rule::exists((new Category)->getTable(), 'id')],
            'variations.*.attribute_values' => ['array'],
            'variations.*.attribute_values.*' => ['integer', Rule::exists((new AttributeValue)->getTable(), 'id')],
            'variations.*.meta_title' => ['nullable', 'string', 'max:60'],
            'variations.*.meta_description' => ['nullable', 'string', 'max:160'],
            'variations.*.meta_keywords' => ['nullable', 'string', 'max:255'],
            'variations.*.image' => ['nullable', ...self::IMAGE],
            'variations.*.gallery' => ['nullable', 'array'],
            'variations.*.gallery.*' => self::IMAGE,
            'variations.*.removed_image' => ['nullable', 'array'],
            'variations.*.removed_image.*' => ['integer'],
            'variations.*.removed_gallery' => ['nullable', 'array'],
            'variations.*.removed_gallery.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Name is required'),
            'image.image' => __('The file must be a valid image.'),
            'image.mimes' => __('Image must be: JPG, PNG, GIF, or WebP.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = [
            'name' => 'Name', 'slug' => 'Slug', 'sku' => 'Sku', 'barcode' => 'Barcode', 'category_id' => 'Category',
            'base_price' => 'Base Price', 'sale_price' => 'Sale Price', 'cost_price' => 'Cost Price', 'wholesale_price' => 'Wholesale Price',
            'discount_type' => 'Discount Type', 'discount_value' => 'Discount Amount',
            'discount_start_date' => 'Discount Start Date', 'discount_end_date' => 'Discount End Date',
            'max_limit' => 'Max Limit', 'min_limit_1' => 'Min Limit 1', 'min_limit_2' => 'Min Limit 2',
            'meta_title' => 'Meta Title', 'meta_description' => 'Meta Description', 'meta_keywords' => 'Meta Keywords',
            'short_description' => 'Short Description', 'description' => 'Description', 'image' => 'Image',
        ];

        $attributes = [
            'brand_id' => __('Brand'), 'unit_id' => __('Unit'), 'manufacturers' => __('Manufacturers'), 'branches' => __('Branches'),
            'tags' => __('Tags'), 'labels' => __('Labels'), 'weight_per_meter' => __('Weight per Meter'), 'length' => __('Length'),
            'site_area' => __('Section Area'), 'pricing_type' => __('Pricing Type'), 'item_type_profile_id' => __('Item Type'),
            'bundle_count' => __('Bundle Count'), 'profile_thickness' => __('Profile Thickness'), 'is_open_profile' => __('Profile Shape'),
            'area' => __('Area'), 'item_type' => __('Item Nature'), 'service_kind' => __('Service Kind'),
            'service_cost_source' => __('Default Cost Source'), 'revenue_account_id' => __('Revenue Account'),
            'cost_account_id' => __('Cost Account'), 'main_product_id' => __('Main Item'), 'color' => __('Color'), 'gallery.*' => __('Gallery'),
            'accessories.*.accessory_product_id' => __('Item'), 'accessories.*.quantity' => __('Suggested Quantity'),
            'cutting_recipes.*.output_product_id' => __('Item'), 'cutting_recipes.*.pieces_per_unit' => __('Pieces per Unit'),
            'addons.*.addon_id' => __('Addon Group'), 'addons.*.min_selection' => __('Min Selection'),
            'addons.*.max_selection' => __('Max Selection'), 'addons.*.max_quantity_per_option' => __('Max Qty Per Option'),
            'addons.*.allowed_options.*.addon_option_id' => __('Option'), 'addons.*.allowed_options.*.price' => __('Override Price'),
        ];

        foreach ($names as $field => $label) {
            $attributes[$field] = __($label);
            $attributes["variations.*.{$field}"] = __($label);
        }

        return $attributes;
    }

    /**
     * حدود المخزون الثلاثة تُتحقق معاً (StockLimits)، للصنف ولكل متغير. لا تُتحقق لمن لا يعدّلها:
     * الخدمة تحذفها (ProductService).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! StockLimits::canEdit()) {
                return;
            }

            $data = $this->validationData();
            $rows = ['' => $data];

            if (($data['pricing_type'] ?? null) !== 'weight') {
                foreach ((array) ($data['variations'] ?? []) as $index => $row) {
                    $rows["variations.{$index}."] = (array) $row;
                }
            }

            foreach ($rows as $prefix => $row) {
                if ($prefix === '' && ($row['item_type'] ?? null) === ItemType::SERVICE->value) {
                    continue;
                }

                foreach (StockLimits::errors($row['max_limit'] ?? null, $row['min_limit_1'] ?? null, $row['min_limit_2'] ?? null) as $field => $message) {
                    $validator->errors()->add($prefix.$field, $message);
                }
            }
        }];
    }

    /**
     * ما يأخذه ProductService: القوائم بترتيب النموذج.
     *
     * @return array<string, mixed>
     */
    public function forService(): array
    {
        $data = $this->validated();

        foreach (['variations', 'addons', 'accessories', 'cutting_recipes'] as $list) {
            if (isset($data[$list]) && is_array($data[$list])) {
                ksort($data[$list]);
                $data[$list] = array_values($data[$list]);
            }
        }

        foreach ($data['addons'] ?? [] as $index => $addon) {
            if (isset($addon['allowed_options'])) {
                ksort($addon['allowed_options']);
                $data['addons'][$index]['allowed_options'] = array_values($addon['allowed_options']);
            }
        }

        // الحقول غير المذكورة في القواعد لا تصل الى validated(): متغير محفوظ يحتاج معرّفه.
        $raw = (array) ($this->validationData()['variations'] ?? []);
        ksort($raw);
        $raw = array_values($raw);

        foreach ($data['variations'] ?? [] as $index => $row) {
            $data['variations'][$index]['id'] = $raw[$index]['id'] ?? null;
            $data['variations'][$index]['slug'] = $raw[$index]['slug'] ?? null;
            $data['variations'][$index]['sku'] = $raw[$index]['sku'] ?? null;
        }

        return $data;
    }

    private function productId(): int
    {
        return (int) $this->route('product');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $data = $this->filled('payload') ? (array) json_decode((string) $this->input('payload'), true) : $this->except('payload');
        $data = self::emptyToNull($data);

        // الرابط بصيغة الروابط، او فارغ فيُصنع من الاسم عند الحفظ.
        $data['slug'] = filled($data['slug'] ?? null) ? Str::slug((string) $data['slug']) : null;
        $data['pricing_type'] ??= 'simple';
        $data['item_type'] ??= ItemType::GOODS->value;

        foreach (['tags', 'labels', 'category_id', 'manufacturers', 'branches', 'accessories', 'cutting_recipes'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = (array) ($data[$list] ?? []);
            }
        }

        foreach ((array) ($data['addons'] ?? []) as $index => $addon) {
            if (is_array($addon)) {
                $data['addons'][$index]['is_required'] = (bool) ($addon['is_required'] ?? false);
                $data['addons'][$index]['allowed_options'] = array_map(
                    fn ($option) => is_array($option) ? [...$option, 'is_default' => (bool) ($option['is_default'] ?? false)] : $option,
                    (array) ($addon['allowed_options'] ?? []),
                );
            }
        }

        // الملفات بمساراتها في النموذج.
        $files = $this->allFiles();

        if (isset($files['image'])) {
            $data['image'] = $files['image'];
        }

        if (isset($files['gallery'])) {
            $data['gallery'] = (array) $files['gallery'];
        }

        foreach ((array) ($files['variations'] ?? []) as $index => $rowFiles) {
            if (! isset($data['variations'][$index]) || ! is_array($rowFiles)) {
                continue;
            }

            if (isset($rowFiles['image'])) {
                $data['variations'][$index]['image'] = $rowFiles['image'];
            }

            if (isset($rowFiles['gallery'])) {
                $data['variations'][$index]['gallery'] = (array) $rowFiles['gallery'];
            }
        }

        return $data;
    }

    /**
     * Options must belong to their row's addon.
     *
     * @return array<string, mixed>
     */
    private function allowedOptionRules(): array
    {
        $rules = [];
        $table = (new AddonOption)->getTable();

        foreach ((array) ($this->validationData()['addons'] ?? []) as $index => $addon) {
            $addonId = is_array($addon) && is_numeric($addon['addon_id'] ?? null) ? (int) $addon['addon_id'] : 0;

            foreach ((array) ($addon['allowed_options'] ?? []) as $position => $option) {
                $rules["addons.{$index}.allowed_options.{$position}.addon_option_id"] = ['required', 'integer', Rule::exists($table, 'id')->where('addon_id', $addonId)];
            }
        }

        return $rules;
    }

    /**
     * A row's id must be a variation of this product; its typed slug and sku are unique apart from itself.
     *
     * @return array<string, mixed>
     */
    private function variationRowRules(int $productId, string $table): array
    {
        $rules = [];

        foreach ((array) ($this->validationData()['variations'] ?? []) as $index => $row) {
            $rowId = is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : null;

            $rules["variations.{$index}.id"] = ['nullable', 'integer', Rule::exists($table, 'id')->where('parent_id', $productId ?: -1)];
            $rules["variations.{$index}.slug"] = ['nullable', 'string', 'max:255', Rule::unique($table, 'slug')->ignore($rowId)->whereNull('deleted_at')];
            $rules["variations.{$index}.sku"] = ['nullable', 'string', 'max:255', Rule::unique($table, 'sku')->ignore($rowId)];
        }

        return $rules;
    }

    /**
     * @param  Closure(mixed): ?string  $check
     */
    private function closure(Closure $check): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($check): void {
            if ($message = $check($value)) {
                $fail($message);
            }
        };
    }

    /**
     * ConvertEmptyStringsToNull has not run on the decoded JSON.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private static function emptyToNull(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::emptyToNull($value);
            } elseif (is_string($value) && trim($value) === '') {
                $data[$key] = null;
            }
        }

        return $data;
    }
}

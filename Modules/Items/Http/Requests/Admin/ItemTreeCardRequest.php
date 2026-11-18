<?php

namespace Modules\Items\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Support\StockLimits;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;
use Modules\Items\Enums\ProfileThickness;
use Modules\Items\Enums\ServiceCostSource;
use Modules\Items\Enums\ServiceKind;
use Modules\Items\Services\ItemTreeService;
use Modules\Items\Support\ItemCard;
use Modules\Items\Support\ItemCardRules;

/**
 * بطاقة الصنف في شجرة الاصناف. الحقل الذي لا يظهر في البطاقة يُستبعد (exclude) فلا يُتحقق
 * منه ولا يُحفظ: اسعار صنف الوزن، تكلفة المقطع، حقول الالمنيوم لصنف الكمية، اعدادات الخدمة
 * للسلعة، والحدود لمن لا يملك «تعديل الحدود». رقم الصنف لا يُقبل من الواجهة ابداً.
 *
 * اضافة مقطع من الشجرة (fixed_main_id) تثبّت النوع (بالوزن، سلعة) والاب.
 */
class ItemTreeCardRequest extends FormRequest
{
    private ?Product $card = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('fixed_main_id') && ! $this->route('product')) {
            $this->merge([
                'main_product_id' => (int) $this->input('fixed_main_id'),
                'pricing_type' => 'weight',
                'item_type' => ItemType::GOODS->value,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $productId = $this->cardProduct()?->id;
        $isService = 'exclude_unless:item_type,'.ItemType::SERVICE->value;

        return [
            'category_id' => ['nullable', 'integer'],
            'fixed_main_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                $main = Product::query()->find($value);

                if (! $main || ! app(ItemTreeService::class)->isMain($main)) {
                    $fail(__('The selected main item was not found.'));
                }
            }],
            'barcode' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                $isPiece = $this->filled('fixed_main_id') || $this->filled('main_product_id');

                if ($error = ItemCard::duplicateNameError($value, $this->cardProduct(), $isPiece)) {
                    $fail($error);
                }
            }],
            'item_type' => ['required', Rule::in(array_column(ItemType::cases(), 'value'))],
            'pricing_type' => ['required', Rule::in(['simple', 'weight']), function (string $attribute, mixed $value, Closure $fail) use ($productId): void {
                if ($error = ItemCardRules::weightPricingError($value, $productId)) {
                    $fail($error);
                }
            }],
            'main_product_id' => ['exclude_if:item_type,'.ItemType::SERVICE->value, 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($productId): void {
                if ($error = ItemCardRules::mainProductError($value, $this->input('pricing_type'), $productId)) {
                    $fail($error);
                }
            }],
            'base_price' => ['exclude_if:pricing_type,weight', 'required', 'numeric'],
            'service_kind' => [$isService, 'nullable', Rule::in(array_column(ServiceKind::cases(), 'value'))],
            'service_cost_source' => [$isService, 'nullable', Rule::in(array_column(ServiceCostSource::cases(), 'value'))],
            'revenue_account_id' => [$isService, 'nullable', 'integer'],
            'cost_account_id' => [$isService, 'nullable', 'integer'],
            'length' => ['nullable', 'numeric'],
            'weight_per_meter' => ['nullable', 'numeric'],
            'synonym_product_id' => ['nullable', 'integer', Rule::exists((new Product)->getTable(), 'id'), ...($productId ? [Rule::notIn([$productId])] : [])],
            'shelf' => ['nullable', 'string', 'max:50'],
            'site_area' => ['nullable', 'numeric'],
            'color' => ['nullable', 'string', 'max:50'],
            ...$this->limitRules(),
            'unit_id' => ['nullable', 'integer'],
            'item_type_profile_id' => ['nullable', 'integer'],
            // حقول مقطع الالمنيوم لاصناف الوزن فقط.
            'bundle_count' => ['exclude_unless:pricing_type,weight', 'nullable', 'integer', 'min:0'],
            'profile_thickness' => ['exclude_unless:pricing_type,weight', 'nullable', Rule::in(array_column(ProfileThickness::cases(), 'value'))],
            'is_open_profile' => ['exclude_unless:pricing_type,weight', 'nullable', 'in:0,1'],
            'area' => ['exclude_unless:pricing_type,weight', 'nullable', 'numeric', 'min:0'],
            'short_description' => ['nullable', 'string'],
            'supplier_ids' => ['nullable', 'array'],
            'supplier_ids.*' => ['integer'],
            'apply_to_colors' => ['nullable', 'boolean'],
            'apply_to_color_ids' => ['nullable', 'array'],
            'apply_to_color_ids.*' => ['integer'],
            'image' => ['nullable', 'image', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    /**
     * الحالة التي تُحفظ: ما تحقق منه دون سياق الشجرة والصورة.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $state = collect($this->validated())->except(['category_id', 'fixed_main_id', 'image', 'remove_image'])->all();
        $state['apply_to_colors'] = $this->boolean('apply_to_colors');

        return $state;
    }

    public function cardProduct(): ?Product
    {
        $id = $this->route('product');

        return $id ? ($this->card ??= Product::query()->find($id)) : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Item Name'),
            'main_product_id' => __('Belongs to Item'),
            'base_price' => __('Base Price'),
            'synonym_product_id' => __('Synonym Item'),
        ];
    }

    /**
     * الحدود الثلاثة بقاعدة واحدة (StockLimits)، ولا تصل الا من صاحب «تعديل الحدود».
     *
     * @return array<string, list<mixed>>
     */
    private function limitRules(): array
    {
        $rules = [];

        foreach (StockLimits::FIELDS as $field) {
            $rules[$field] = [
                Rule::excludeIf(fn (): bool => ! StockLimits::canEdit()),
                'nullable',
                'numeric',
                'min:0',
                function (string $attribute, mixed $value, Closure $fail) use ($field): void {
                    $errors = StockLimits::errors($this->input('max_limit'), $this->input('min_limit_1'), $this->input('min_limit_2'));

                    if (isset($errors[$field])) {
                        $fail($errors[$field]);
                    }
                },
            ];
        }

        return $rules;
    }
}

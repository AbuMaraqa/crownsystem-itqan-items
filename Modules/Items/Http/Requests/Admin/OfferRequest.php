<?php

namespace Modules\Items\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Branch\Entities\Branch;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Offer;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\OfferType;
use Modules\Items\Repositories\Contracts\OfferRepositoryInterface;
use Modules\Items\Services\OfferService;

/**
 * Add picks one or more branches (one offer each); Edit is one offer, so one branch.
 */
class OfferRequest extends FormRequest
{
    private const MONEY_MAX = 'max:99999999.99'; // decimal(10, 2)

    private const INT_MAX = 'max:2147483647';

    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = (int) $this->route('offer');
        $branches = (new Branch)->getTable();

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'], // a varchar column
            ...($id
                ? ['branch_id' => ['required', 'integer', Rule::exists($branches, 'id')]]
                : [
                    'branch_id' => ['required', 'array', 'min:1'],
                    'branch_id.*' => ['integer', 'distinct', Rule::exists($branches, 'id')],
                ]),
            'offerable_model' => ['required', Rule::in(array_keys(OfferService::TARGETS))],
            'offerable_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(OfferType::class)],
            'value' => ['required', 'numeric', 'min:0', self::MONEY_MAX],
            'code' => ['nullable', 'string', 'max:50', Rule::unique((new Offer)->getTable(), 'code')->ignore($id ?: null)],
            'min_purchase_amount' => ['required', 'numeric', 'min:0', self::MONEY_MAX],
            'usage_limit' => ['required', 'integer', 'min:1', self::INT_MAX],
            'usage_limit_per_user' => ['required', 'integer', 'min:1', self::INT_MAX],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],
            'image' => [$this->imageIsRequired($id) ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:10000'],
            'removed_image' => ['nullable', 'array'],
            'removed_image.*' => ['integer'],
        ];
    }

    /**
     * The old page's messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Name is required'),
            'image.required' => __('Image is required'),
            'image.image' => __('The file must be a valid image.'),
            'image.mimes' => __('Image must be: JPG, PNG, GIF, or WebP.'),
            'branch_id.required' => __('Branch is required'),
            'branch_id.exists' => __('Selected branch does not exist'),
            'branch_id.*.exists' => __('Selected branch does not exist'),
            'value.required' => __('Value is required'),
            'value.numeric' => __('Value must be a number'),
            'value.min' => __('Value must be at least 0'),
            'code.unique' => __('Code must be unique'),
            'code.max' => __('Code must not exceed 50 characters'),
            'min_purchase_amount.numeric' => __('Min Purchase Amount must be a number'),
            'min_purchase_amount.min' => __('Min Purchase Amount must be at least 0'),
            'usage_limit.integer' => __('Usage Limit must be an integer'),
            'usage_limit.min' => __('Usage Limit must be at least 1'),
            'usage_limit_per_user.integer' => __('Usage Limit Per User must be an integer'),
            'usage_limit_per_user.min' => __('Usage Limit Per User must be at least 1'),
            'start_date.date' => __('Start Date must be a valid date'),
            'end_date.date' => __('End Date must be a valid date'),
            'end_date.after_or_equal' => __('End Date must be a date after or equal to Start Date'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name'),
            'description' => __('Description'),
            'branch_id' => __('Branch'),
            'branch_id.*' => __('Branch'),
            'offerable_model' => __('Offerable Model'),
            'offerable_id' => __('Offerable ID'),
            'type' => __('Type'),
            'value' => __('Value'),
            'code' => __('Code'),
            'min_purchase_amount' => __('Min Purchase Amount'),
            'usage_limit' => __('Usage Limit'),
            'usage_limit_per_user' => __('Usage Limit Per User'),
            'start_date' => __('Start Date'),
            'end_date' => __('End Date'),
            'image' => __('Image'),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branches = array_map('intval', (array) $this->input('branch_id'));

            // The code column is unique and add makes one offer per branch.
            if (filled($this->input('code')) && count($branches) > 1) {
                $validator->errors()->add('code', __('A code can only be used with one branch.'));
            }

            // As the old page's list: a category, or a main product sold in one of the chosen branches.
            $id = (int) $this->input('offerable_id');
            $exists = $this->input('offerable_model') === 'items_product'
                ? Product::query()->whereKey($id)->whereNull('parent_id')
                    ->whereHas('branches', fn ($query) => $query->whereIn(config('items.table_prefix').'branch_product.branch_id', $branches))
                    ->exists()
                : Category::query()->whereKey($id)->exists();

            if (! $exists) {
                $validator->errors()->add('offerable_id', __('validation.exists', ['attribute' => __('Offerable ID')]));
            }
        }];
    }

    private function imageIsRequired(int $id): bool
    {
        if (! $id) {
            return true;
        }

        $saved = app(OfferRepositoryInterface::class)->findForForm($id)->getFirstMedia('offer');

        return ! $saved || in_array($saved->id, array_map('intval', (array) $this->input('removed_image', [])), true);
    }
}

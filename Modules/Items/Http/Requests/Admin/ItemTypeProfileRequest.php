<?php

namespace Modules\Items\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Enums\ItemBonusMode;
use Modules\Items\Enums\ItemDateMode;
use Modules\Items\Enums\ItemSerialMode;
use Modules\Items\Enums\ItemSubItemMode;

class ItemTypeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    /**
     * The limited number fields keep their rules in the model (ItemTypeProfile::rangeRules()).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique((new ItemTypeProfile)->getTable(), 'code')->ignore($this->route('profile')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
            ...ItemTypeProfile::rangeRules(),
            'date_mode' => ['required', Rule::enum(ItemDateMode::class)],
            'bonus_mode' => ['required', Rule::enum(ItemBonusMode::class)],
            'serial_mode' => ['required', Rule::enum(ItemSerialMode::class)],
            'sub_item_mode' => ['required', Rule::enum(ItemSubItemMode::class)],
            'has_color' => ['boolean'],
            'has_size' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => __('Code'),
            'name' => __('Name'),
            'sort_order' => __('Sort Order'),
            'quantity_decimals' => __('Quantity Decimals'),
            'price_decimals' => __('Price Decimals'),
            'notes_count' => __('Line Notes'),
            'date_mode' => __('Date'),
            'bonus_mode' => __('Free Quantity'),
            'serial_mode' => __('Serial'),
            'sub_item_mode' => __('Sub Items'),
            'dimensions' => __('Dimensions'),
            'has_color' => __('Has Color'),
            'has_size' => __('Has Size'),
            'is_active' => __('Is Active'),
        ];
    }
}

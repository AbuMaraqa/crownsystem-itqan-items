<?php

namespace Modules\Items\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\Finish;

/**
 * جدول «الألوان وأسعار الكيلو» في صفحة التصنيف: كل صف لون من القائمة (finish_id) او لون جديد
 * باسمه (name)، وسعر بيع الكيلو وشرائه في كل قائمة سعر. الحقل الفارغ يعني بلا سعر.
 */
class CategoryColoursRequest extends FormRequest
{
    private const PRICE = ['nullable', 'numeric', 'min:0', 'max:99999999999999.9999']; // decimal(18, 4)

    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoryId = (int) $this->route('category');

        return [
            'rows' => ['present', 'array', 'max:500'],
            'rows.*.id' => ['nullable', 'integer', Rule::exists((new CategoryFinish)->getTable(), 'id')->where('category_id', $categoryId)],
            'rows.*.finish_id' => ['nullable', 'required_without:rows.*.name', 'integer', 'distinct', Rule::exists((new Finish)->getTable(), 'id')->whereNull('deleted_at')],
            'rows.*.name' => ['nullable', 'required_without:rows.*.finish_id', 'string', 'max:100'],
            'rows.*.is_active' => ['boolean'],
            'rows.*.prices' => ['array'],
            'rows.*.prices.*.sell' => self::PRICE,
            'rows.*.prices.*.buy' => self::PRICE,
        ];
    }

    /**
     * A new colour typed twice, or typed with the name of a colour already in the table, is one colour.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $seen = [];
            $names = Finish::query()->whereIn('id', array_filter(array_column((array) $this->input('rows', []), 'finish_id')))->pluck('name')->map(fn ($name) => mb_strtolower(trim((string) $name)))->all();

            foreach ((array) $this->input('rows', []) as $index => $row) {
                if (! is_array($row) || filled($row['finish_id'] ?? null) || blank($row['name'] ?? null)) {
                    continue;
                }

                $key = mb_strtolower(trim((string) $row['name']));

                if (isset($seen[$key]) || in_array($key, $names, true)) {
                    $validator->errors()->add("rows.{$index}.name", __('This colour is already in the table.'));
                }

                $seen[$key] = true;
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rows.*.finish_id' => __('Colour'),
            'rows.*.name' => __('Colour'),
            'rows.*.prices.*.sell' => __('Sell Price'),
            'rows.*.prices.*.buy' => __('Buy Price'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $rows = (array) $this->input('rows', []);

        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $rows[$index]['is_active'] = filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
            }
        }

        $this->merge(['rows' => $rows]);
    }
}

<?php

namespace Modules\Items\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Items\Entities\Manufacturer;
use Modules\Items\Http\Requests\Admin\Concerns\NormalizesSlug;

class ManufacturerRequest extends FormRequest
{
    use NormalizesSlug;

    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique((new Manufacturer)->getTable(), 'slug')->ignore($this->route('manufacturer'))],
            'description' => ['nullable', 'string', 'max:255'], // the column is varchar(255)
            // Optional; on edit the saved image stays when none is sent.
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
            'removed_image' => ['nullable', 'array'],
            'removed_image.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name'),
            'slug' => __('Slug'),
            'description' => __('Description'),
            'image' => __('Image'),
        ];
    }
}

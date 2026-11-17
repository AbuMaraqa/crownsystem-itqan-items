<?php

namespace Modules\Items\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Items\Entities\Category;
use Modules\Items\Repositories\Contracts\CategoryRepositoryInterface;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    /**
     * The slug the server keeps: the typed one in URL form, or one made from the name when it is
     * left empty (as the Livewire form filled it from the name). Str::slug also transliterates an
     * Arabic name, which the browser cannot do.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name'))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $table = (new Category)->getTable();

        return [
            'name' => ['required', 'string', 'max:255'],
            // The unique index covers soft deleted categories too.
            'slug' => ['required', 'string', 'max:255', Rule::unique($table, 'slug')->ignore($this->route('category'))],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists($table, 'id')->whereNull('deleted_at'),
                $this->notInsideItself(...),
            ],
            'description' => ['nullable', 'string'],
            // الصورة اختيارية للتصنيف.
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
            'removed_image' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Name is required'),
            'slug.required' => __('Slug is required'),
            'image.image' => __('The file must be a valid image.'),
            'image.mimes' => __('Image must be: JPG, PNG, GIF, or WebP.'),
            'image.max' => __('Image size must not exceed 2MB.'),
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
            'parent_id' => __('Parent Category'),
            'description' => __('Description'),
            'image' => __('Image'),
        ];
    }

    /**
     * A category under itself or under one of its children would leave the tree.
     */
    private function notInsideItself(string $attribute, mixed $value, Closure $fail): void
    {
        $id = $this->route('category');

        if ($id === null) {
            return;
        }

        $categories = app(CategoryRepositoryInterface::class);
        /** @var Category|null $category */
        $category = $categories->find($id);

        if ($category && in_array((int) $value, $categories->selfAndDescendantIds($category), true)) {
            $fail(__('A category cannot be moved into one of its own subcategories.'));
        }
    }
}

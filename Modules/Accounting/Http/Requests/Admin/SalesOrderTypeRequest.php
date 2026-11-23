<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SalesOrderTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            // المهلة القصوى لجاهزية الطلبية للفوترة بالأيام من تأكيدها.
            'lead_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name'),
            'description' => __('When to use'),
            'lead_days' => __('Maximum lead time (days)'),
            'is_active' => __('Active'),
        ];
    }
}

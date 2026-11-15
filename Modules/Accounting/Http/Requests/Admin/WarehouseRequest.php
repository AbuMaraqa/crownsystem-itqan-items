<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Entities\Warehouse;
use Modules\Branch\Entities\Branch;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // وسيط can على المسار يقرر
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code')) && trim($this->input('code')) === '') {
            $this->merge(['code' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique((new Warehouse)->getTable(), 'code')->ignore($this->route('warehouse'))],
            'branch_id' => ['bail', 'nullable', 'integer', Rule::exists((new Branch)->getTable(), 'id')],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
            'address' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Name is required'),
            'name.string' => __('Name must be a string'),
            'code.unique' => __('Code already exist'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name'),
            'code' => __('Code'),
            'branch_id' => __('Branch'),
            'phone' => __('Phone'),
            'email' => __('Email'),
            'latitude' => __('Latitude'),
            'longitude' => __('Longitude'),
            'is_active' => __('Active'),
            'address' => __('Address'),
            'description' => __('Description'),
        ];
    }
}

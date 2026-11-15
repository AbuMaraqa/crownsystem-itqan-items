<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Core\Entities\User;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // وسيط can على المسار يقرر
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $email = Str::lower(trim($this->input('email')));
            $this->merge(['email' => $email === '' ? null : $email]);
        }
    }

    /**
     * المورد قد لا يملك بريداً الكترونياً.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique((new User)->getTable(), 'email')->ignore($this->route('supplier'))],
            'phone' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name'),
            'email' => __('Email'),
            'phone' => __('Phone'),
            'tax_number' => __('Tax Number'),
            'address' => __('Address'),
        ];
    }
}

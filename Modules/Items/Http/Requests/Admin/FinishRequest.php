<?php

namespace Modules\Items\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Items\Entities\Finish;
use Modules\Items\Http\Requests\Admin\Concerns\ColorRule;

class FinishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name')), 'code' => trim((string) $this->input('code')) ?: null, 'is_active' => $this->boolean('is_active', true)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // اسم لون محذوف يعيده الحفظ (FinishService::create)، فالتكرار يُحسب بين الالوان القائمة.
            'name' => ['required', 'string', 'max:150', Rule::unique((new Finish)->getTable(), 'name')->whereNull('deleted_at')->ignore($this->route('finish'))],
            // الرقم لا يتكرر ولو بين المحذوفة، فرقم المحذوف لا يُعاد (ItemCode::nextFinish).
            'code' => ['nullable', 'string', 'max:50', Rule::unique((new Finish)->getTable(), 'code')->ignore($this->route('finish'))],
            'color_code' => ['nullable', 'string', ColorRule::HEX],
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
            'code' => __('Code'),
            'color_code' => __('Color'),
            'is_active' => __('Is Active'),
        ];
    }
}

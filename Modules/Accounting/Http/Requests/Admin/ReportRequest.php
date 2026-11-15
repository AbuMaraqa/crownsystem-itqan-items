<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Services\CustomerHistoryService;

/**
 * فلاتر التقارير المالية من رابط الصفحة (GET)، فيُحفظ التقرير في المفضلة كما هو.
 * اسماء المفاتيح هي نفسها التي كانت صفحات Livewire تضعها في الرابط (withGroups، asOf، client، mode).
 * التبويب ونوع الكشف غير المعروفين يعودان للافتراضي بدل رفض الصفحة.
 */
class ReportRequest extends FormRequest
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    protected function prepareForValidation(): void
    {
        foreach (['withGroups', 'includeZero'] as $flag) {
            if ($this->exists($flag)) {
                $this->merge([$flag => $this->boolean($flag)]);
            }
        }

        if ($this->exists('tab') && ! in_array($this->input('tab'), CustomerHistoryService::TABS, true)) {
            $this->merge(['tab' => CustomerHistoryService::TABS[0]]);
        }

        if ($this->exists('mode') && ! in_array($this->input('mode'), CustomerHistoryService::STATEMENT_MODES, true)) {
            $this->merge(['mode' => CustomerHistoryService::STATEMENT_MODES[0]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'asOf' => ['nullable', 'date_format:Y-m-d'],
            'withGroups' => ['boolean'],
            'includeZero' => ['boolean'],
            'account_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
            'client' => ['nullable', 'integer'],
            'tab' => ['nullable', Rule::in(CustomerHistoryService::TABS)],
            'mode' => ['nullable', Rule::in(CustomerHistoryService::STATEMENT_MODES)],
            'group' => ['nullable', Rule::in([CustomerHistoryService::SALES_GROUP, CustomerHistoryService::PURCHASES_GROUP, CustomerHistoryService::FINANCE_GROUP])],
            'document_search' => ['nullable', 'string', 'max:255'],
            'item_search' => ['nullable', 'string', 'max:255'],
            'movement_search' => ['nullable', 'string', 'max:255'],
            'product' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => __('From'),
            'to' => __('To'),
            'asOf' => __('As of'),
            'account_id' => __('Account'),
            'search' => __('Search'),
            'client' => __('Client'),
            'group' => __('Documents'),
            'product' => __('Item'),
        ];
    }

    /**
     * التاريخ من الرابط: غيابه يعني القيمة الافتراضية، والحقل الفارغ يعني «بلا حد».
     */
    public function dateFilter(string $key, ?string $default = null): ?string
    {
        if (! $this->exists($key)) {
            return $default;
        }

        return $this->validated($key) ?: null;
    }

    public function flagFilter(string $key, bool $default): bool
    {
        return $this->exists($key) ? (bool) $this->validated($key) : $default;
    }

    public function textFilter(string $key): string
    {
        return trim((string) $this->validated($key, ''));
    }

    public function idFilter(string $key): ?int
    {
        $value = $this->validated($key);

        return $value ? (int) $value : null;
    }
}

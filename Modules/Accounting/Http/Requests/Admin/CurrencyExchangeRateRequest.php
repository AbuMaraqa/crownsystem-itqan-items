<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Entities\Currency;

/**
 * The base currency is not sent: a new rate takes the system's base currency, an edited one
 * keeps its own (CurrencyExchangeRateService).
 */
class CurrencyExchangeRateRequest extends FormRequest
{
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
            'currency_id' => ['required', 'integer', Rule::exists(Currency::class, 'id')],
            'rate_date' => ['required', 'date_format:Y-m-d'],
            'exchange_rate' => ['required', 'numeric', 'min:0.00000001'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'currency_id' => __('Currency'),
            'rate_date' => __('Rate Date'),
            'exchange_rate' => __('Exchange Rate'),
            'is_active' => __('Active'),
        ];
    }
}

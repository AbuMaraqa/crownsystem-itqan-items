<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Entities\CurrencyExchangeRate;
use Modules\Core\Entities\Currency;
use Modules\Core\Settings\GeneralSettings;
use Modules\Core\Support\SafeDelete;

class CurrencyExchangeRateService
{
    public function baseCurrencyId(): ?int
    {
        $settings = app(GeneralSettings::class);

        return $settings->base_currency_id
            ?: Currency::query()->where('is_default', true)->value('id')
            ?: Currency::query()->value('id');
    }

    public function rateFor(?int $currencyId, ?string $date = null): ?float
    {
        $baseCurrencyId = $this->baseCurrencyId();

        if (! $currencyId || ! $baseCurrencyId) {
            return null;
        }

        if ((int) $currencyId === (int) $baseCurrencyId) {
            return 1.0;
        }

        $date = $date ?: now()->toDateString();

        $rate = CurrencyExchangeRate::query()
            ->active()
            ->where('base_currency_id', $baseCurrencyId)
            ->where('currency_id', $currencyId)
            ->whereDate('rate_date', '<=', $date)
            ->latest('rate_date')
            ->value('exchange_rate');

        return $rate ? (float) $rate : null;
    }

    /**
     * سعر الصرف واحد لكل عملة وتاريخ: الاضافة تحدّث السعر الموجود لنفس العملة والتاريخ
     * (وتعيد المحذوف منه) بدل تكراره. العملة الاساسية هي عملة النظام دائماً.
     *
     * @param  array{currency_id: int, rate_date: string, exchange_rate: mixed, is_active?: bool}  $data
     *
     * @throws ValidationException when the system has no base currency
     */
    public function save(array $data, ?int $userId): CurrencyExchangeRate
    {
        $baseCurrencyId = $this->baseCurrencyId();

        if (! $baseCurrencyId) {
            throw ValidationException::withMessages([
                'base_currency_id' => __('validation.required', ['attribute' => __('Base Currency')]),
            ]);
        }

        $rate = CurrencyExchangeRate::withTrashed()->updateOrCreate(
            [
                'base_currency_id' => $baseCurrencyId,
                'currency_id' => $data['currency_id'],
                'rate_date' => $data['rate_date'],
            ],
            [
                'exchange_rate' => $data['exchange_rate'],
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $userId,
            ]
        );

        if ($rate->trashed()) {
            $rate->restore();
        }

        return $rate;
    }

    /**
     * العملة الاساسية للسعر لا تتغير عند التعديل.
     *
     * @param  array{currency_id: int, rate_date: string, exchange_rate: mixed, is_active?: bool}  $data
     *
     * @throws DomainException when another rate exists for the same currency and date
     */
    public function update(CurrencyExchangeRate $rate, array $data): CurrencyExchangeRate
    {
        $exists = CurrencyExchangeRate::withTrashed()
            ->where('base_currency_id', $rate->base_currency_id)
            ->where('currency_id', $data['currency_id'])
            ->whereDate('rate_date', $data['rate_date'])
            ->whereKeyNot($rate->id)
            ->exists();

        if ($exists) {
            throw new DomainException(__('Exchange rate already exists for selected currency and date.'));
        }

        unset($data['base_currency_id'], $data['created_by']);
        $rate->update($data);

        return $rate;
    }

    /**
     * false اذا رفضت قاعدة البيانات الحذف لارتباط السعر بسجلات اخرى.
     */
    public function delete(CurrencyExchangeRate $rate): bool
    {
        return SafeDelete::attempt(fn () => $rate->delete());
    }
}

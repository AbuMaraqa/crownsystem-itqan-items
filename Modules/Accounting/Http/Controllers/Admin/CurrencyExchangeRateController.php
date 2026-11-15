<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\CurrencyExchangeRate;
use Modules\Accounting\Http\Requests\Admin\CurrencyExchangeRateRequest;
use Modules\Accounting\Repositories\Contracts\CurrencyExchangeRateRepositoryInterface;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Support\SelectOptions;
use Modules\Core\Entities\Currency;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\SafeDelete;
use Modules\Items\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedSort;

/**
 * اسعار صرف العملات مقابل العملة الاساسية للنظام، سعر لكل عملة وتاريخ. الاضافة والتعديل
 * في نافذة على صفحة القائمة.
 */
class CurrencyExchangeRateController extends Controller
{
    public function __construct(
        private readonly CurrencyExchangeRateRepositoryInterface $rates,
        private readonly CurrencyExchangeRateService $rateService,
    ) {}

    public function index(): Response
    {
        $baseCurrencyId = $this->rateService->baseCurrencyId();

        return Inertia::render('Accounting/CurrencyExchangeRate/Index', [
            'table' => AdminTable::for($this->rates->tableQuery())
                ->filters(
                    AdminFilter::exact('currency_id'),
                    AdminFilter::boolean('is_active'),
                    ...AdminFilter::dateRange('rate_date'),
                    ...AdminFilter::numberRange('exchange_rate'),
                )
                ->sorts(
                    AllowedSort::callback('base_currency', $this->rates->currencyCodeSort('base_currency_id')),
                    AllowedSort::callback('currency', $this->rates->currencyCodeSort('currency_id')),
                    'rate_date',
                    'exchange_rate',
                    'id',
                )
                ->defaultSort('-rate_date')
                ->rows(fn (CurrencyExchangeRate $rate) => [
                    'id' => $rate->id,
                    'base_currency' => $rate->baseCurrency?->code,
                    'currency_id' => (int) $rate->currency_id,
                    'currency' => $rate->currency?->code,
                    'rate_date' => $rate->rate_date?->toDateString(),
                    'rate_date_label' => $rate->rate_date?->format('d/m/Y'),
                    'exchange_rate' => (string) $rate->exchange_rate,
                    'exchange_rate_label' => number_format((float) $rate->exchange_rate, 8),
                    'is_active' => (bool) $rate->is_active,
                ]),
            'currencies' => SelectOptions::currencies(),
            'baseCurrency' => $baseCurrencyId ? Currency::query()->whereKey($baseCurrencyId)->value('code') : null,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(CurrencyExchangeRateRequest $request): RedirectResponse
    {
        $this->rateService->save($request->validated(), $request->user()->id);

        return back()->with('success', __('Exchange rate saved successfully'));
    }

    public function update(CurrencyExchangeRateRequest $request, int $rate): RedirectResponse
    {
        /** @var CurrencyExchangeRate $model */
        $model = $this->rates->findOrFail($rate);

        try {
            $this->rateService->update($model, $request->validated());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Exchange rate updated successfully'));
    }

    public function destroy(int $rate): RedirectResponse
    {
        /** @var CurrencyExchangeRate $model */
        $model = $this->rates->findOrFail($rate);

        if (! $this->rateService->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Exchange rate deleted successfully'));
    }

    public function info(int $rate): JsonResponse
    {
        return response()->json(RecordInfo::of($this->rates->findOrFail($rate)));
    }
}

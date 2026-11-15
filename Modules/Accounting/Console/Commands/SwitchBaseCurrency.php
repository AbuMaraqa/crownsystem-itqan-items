<?php

namespace Modules\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\CurrencyExchangeRate;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Core\Entities\Currency;
use Modules\Core\Settings\GeneralSettings;

/**
 * يغيّر العملة الاساسية للشركة (عملة القيود والتقارير) ويعيد حساب اسعار الصرف نسبة اليها.
 *
 * - currencies.exchange_rate يصبح: كم وحدة من العملة تساوي وحدة واحدة من العملة الاساسية.
 * - تُضاف لكل عملة نشطة سعر صرف بتاريخ اليوم في جدول اسعار الصرف المحاسبي:
 *   المبلغ بالعملة الاساسية = المبلغ × السعر.
 *
 * القيود المرحّلة سابقا محسوبة بالعملة القديمة ولا تُحوَّل، لذلك يرفض الامر التنفيذ
 * عند وجودها الا مع --force (مناسب لبيانات تجريبية فقط).
 */
class SwitchBaseCurrency extends Command
{
    protected $signature = 'accounting:switch-base-currency {code : ISO code of the new base currency, e.g. ILS} {--force : Run even when posted journal entries exist}';

    protected $description = 'Change the company base currency and recalculate exchange rates against it';

    public function handle(CurrencyExchangeRateService $rates): int
    {
        $new = Currency::query()->where('code', strtoupper($this->argument('code')))->first();

        if (! $new) {
            $this->error('Currency not found.');

            return self::FAILURE;
        }

        $oldId = $rates->baseCurrencyId();

        if ((int) $oldId === (int) $new->id) {
            $this->info("{$new->code} is already the base currency.");

            return self::SUCCESS;
        }

        $posted = JournalEntry::query()->where('status', JournalEntryStatus::POSTED->value())->count();

        if ($posted > 0 && ! $this->option('force')) {
            $this->error("There are {$posted} posted journal entries recorded in the current base currency. Re-run with --force only if they are test data.");

            return self::FAILURE;
        }

        // currencies.exchange_rate كان نسبة الى العملة القديمة؛ قيمة العملة الجديدة فيه
        // هي عدد وحداتها مقابل وحدة من القديمة.
        $newPerOld = (float) $new->exchange_rate;

        if ($newPerOld <= 0) {
            $this->error("{$new->code} has no exchange rate against the current base currency.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($new, $newPerOld) {
            $today = now()->toDateString();

            foreach (Currency::query()->get() as $currency) {
                $perNew = $currency->is($new) ? 1.0 : round((float) $currency->exchange_rate / $newPerOld, 8);

                $currency->forceFill([
                    'exchange_rate' => $perNew,
                    'is_default' => $currency->is($new),
                ])->save();

                if ($currency->is($new) || ! $currency->is_active || $perNew <= 0) {
                    continue;
                }

                CurrencyExchangeRate::query()->updateOrCreate(
                    ['base_currency_id' => $new->id, 'currency_id' => $currency->id, 'rate_date' => $today],
                    ['exchange_rate' => round(1 / $perNew, 8), 'is_active' => true]
                );
            }

            $settings = app(GeneralSettings::class);
            $settings->base_currency_id = $new->id;
            $settings->save();
        });

        $this->info("Base currency is now {$new->code}.");

        if ($posted > 0) {
            $this->warn("{$posted} posted journal entries keep their old base amounts.");
        }

        return self::SUCCESS;
    }
}

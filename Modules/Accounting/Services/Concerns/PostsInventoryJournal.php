<?php

namespace Modules\Accounting\Services\Concerns;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Items\Entities\Product;

/**
 * قيود المخزون بالعملة الاساسية (الكلفة محسوبة بها اصلاً).
 */
trait PostsInventoryJournal
{
    /**
     * @param  array<int, array{account_id:int, debit:float, credit:float, description?:string}>  $lines
     */
    protected function postInventoryJournal(Model $document, string $sourceType, string $date, string $description, array $lines): ?JournalEntry
    {
        $lines = $this->mergeJournalLines($lines);

        if ($lines === []) {
            return null;
        }

        $currencyId = app(CurrencyExchangeRateService::class)->baseCurrencyId();

        if (! $currencyId) {
            throw new DomainException(__('Base currency is not configured.'));
        }

        $service = app(JournalEntryService::class);
        $entry = $service->createDraft([
            'entry_date' => $date,
            'reference_no' => $document->reference_no,
            'source_module' => 'Inventory',
            'source_type' => $sourceType,
            'sourceable_id' => $document->getKey(),
            'sourceable_type' => $document::class,
            'description' => $description,
            'created_by' => $document->created_by ?? auth()->id(),
        ], array_map(fn (array $line) => [
            'account_id' => $line['account_id'],
            'branch_id' => $document->branch_id ?? null,
            'currency_id' => $currencyId,
            'exchange_rate' => 1,
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'description' => $line['description'] ?? $description,
            'created_by' => $document->created_by ?? auth()->id(),
        ], $lines));

        return $service->post($entry);
    }

    protected function voidInventoryJournal(Model $document, string $description, ?string $date = null): void
    {
        $entry = $document->journalEntry;

        if ($entry && $entry->status === JournalEntryStatus::POSTED) {
            app(JournalEntryService::class)->void($entry, $description, $date);
        }
    }

    /**
     * يجمع سطور نفس الحساب ويصافي مدينها ودائنها، ويحذف الصفري.
     */
    private function mergeJournalLines(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $accountId = (int) $line['account_id'];
            $merged[$accountId] ??= ['account_id' => $accountId, 'net' => 0.0, 'description' => $line['description'] ?? null];
            $merged[$accountId]['net'] = round($merged[$accountId]['net'] + (float) $line['debit'] - (float) $line['credit'], 4);
        }

        return array_values(array_filter(array_map(fn (array $line) => [
            'account_id' => $line['account_id'],
            'debit' => $line['net'] > 0 ? $line['net'] : 0,
            'credit' => $line['net'] < 0 ? -$line['net'] : 0,
            'description' => $line['description'],
        ], $merged), fn (array $line) => $line['debit'] > 0 || $line['credit'] > 0));
    }

    /**
     * وزن القطعة الواحدة من الصنف: طولها × وزن المتر (كما في الطلبيات).
     */
    protected function weightPerUnit(?Product $product): float
    {
        if (! $product) {
            return 0;
        }

        return round((float) $product->length * (float) $product->weight_per_meter, 4);
    }

    /**
     * وزن كمية من الصنف في المخزن: الصنف الرئيسي كميته كغم، وغيره عدد قطع × وزن القطعة.
     */
    protected function stockWeight(?Product $product, float $quantity): float
    {
        if ($product && app(StockLedgerService::class)->storesByWeight($product)) {
            return round($quantity, 4);
        }

        return round($quantity * $this->weightPerUnit($product), 4);
    }

    protected function decimal(mixed $value, float $default = 0, int $scale = 4): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            throw new DomainException(__('Invalid numeric value.'));
        }

        return round((float) $value, $scale);
    }
}

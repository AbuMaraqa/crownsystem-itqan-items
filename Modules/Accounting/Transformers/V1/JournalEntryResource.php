<?php

namespace Modules\Accounting\Transformers\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

class JournalEntryResource extends JsonResource
{
    public static function allowedFields(): array
    {
        return [
            'id',
            'entry_number',
            'entry_date',
            'status',
            'reference_no',
            'description',
            'fiscal_year_id',
            'accounting_period_id',
            'created_at',
            'updated_at',
        ];
    }

    public static function allowedFilters(): array
    {
        return [
            AllowedFilter::exact('id'),
            AllowedFilter::exact('entry_number'),
            AllowedFilter::exact('status'),
            AllowedFilter::exact('reference_no'),
            AllowedFilter::exact('fiscal_year_id'),
            AllowedFilter::exact('accounting_period_id'),
        ];
    }

    public static function allowedSorts(): array
    {
        return [
            AllowedSort::field('id'),
            AllowedSort::field('entry_number'),
            AllowedSort::field('entry_date'),
            AllowedSort::field('status'),
            AllowedSort::field('created_at'),
        ];
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date?->toDateString(),
            'status' => $this->status?->value(),
            'reference_no' => $this->reference_no,
            'description' => $this->description,
            'fiscal_year_id' => $this->fiscal_year_id,
            'accounting_period_id' => $this->accounting_period_id,
            'debit_total_base' => (float) $this->whenLoaded('lines', fn () => $this->lines->sum('debit_base'), 0),
            'credit_total_base' => (float) $this->whenLoaded('lines', fn () => $this->lines->sum('credit_base'), 0),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'account_id' => $line->account_id,
                'account' => $line->account ? [
                    'code' => $line->account->code,
                    'name' => $line->account->name,
                ] : null,
                'branch_id' => $line->branch_id,
                'currency_id' => $line->currency_id,
                'exchange_rate' => (float) $line->exchange_rate,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'debit_base' => (float) $line->debit_base,
                'credit_base' => (float) $line->credit_base,
                'description' => $line->description,
            ])->values()),
        ];
    }
}

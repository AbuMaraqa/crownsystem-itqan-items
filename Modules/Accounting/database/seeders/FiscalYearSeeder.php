<?php

namespace Modules\Accounting\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Enums\AccountingPeriodStatus;

class FiscalYearSeeder extends Seeder
{
    public function run(): void
    {
        $start = Carbon::now()->startOfYear();
        $end = Carbon::now()->endOfYear();

        $fiscalYear = FiscalYear::updateOrCreate(
            [
                'starts_at' => $start->toDateString(),
                'ends_at' => $end->toDateString(),
            ],
            [
                'name' => 'FY '.$start->year,
                'status' => AccountingPeriodStatus::OPEN->value(),
                'is_default' => true,
                'created_by' => null,
            ]
        );

        for ($month = 1; $month <= 12; $month++) {
            $periodStart = Carbon::create($start->year, $month, 1)->startOfMonth();
            $periodEnd = (clone $periodStart)->endOfMonth();

            AccountingPeriod::updateOrCreate(
                [
                    'fiscal_year_id' => $fiscalYear->id,
                    'starts_at' => $periodStart->toDateString(),
                    'ends_at' => $periodEnd->toDateString(),
                ],
                [
                    'name' => $periodStart->format('F Y'),
                    'status' => AccountingPeriodStatus::OPEN->value(),
                    'created_by' => null,
                ]
            );
        }
    }
}

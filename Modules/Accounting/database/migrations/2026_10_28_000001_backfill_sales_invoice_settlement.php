<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فواتير المبيعات المرحّلة قبل هذا الاصلاح بقي «المتبقي» عليها صفراً (الترحيل لم يكن يضبطه)،
 * فلم تظهر في لوحة تخصيص سندات القبض. يُعاد حسابه من التخصيصات: المسدَّد = مجموع
 * التخصيصات، والمتبقي = الاجمالي − المسدَّد. قيمة مشتقة فقط؛ لا يُمس اي قيد.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $invoices = $prefix.'sales_invoices';
        $allocations = $prefix.'payment_allocations';

        if (! Schema::hasTable($invoices) || ! Schema::hasColumn($invoices, 'outstanding_amount') || ! Schema::hasTable($allocations)) {
            return;
        }

        $morph = Modules\Accounting\Entities\SalesInvoice::class;
        $posted = Modules\Accounting\Enums\SalesInvoiceStatus::POSTED->value;

        DB::table($invoices)
            ->where('status', $posted)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->select(['id', 'total_amount'])
            ->chunkById(500, function ($rows) use ($allocations, $invoices, $morph) {
                $paid = DB::table($allocations)
                    ->where('invoice_type', $morph)
                    ->whereIn('invoice_id', $rows->pluck('id'))
                    ->groupBy('invoice_id')
                    ->selectRaw('invoice_id, SUM(amount) as paid')
                    ->pluck('paid', 'invoice_id');

                foreach ($rows as $row) {
                    $amount = round((float) ($paid[$row->id] ?? 0), 4);

                    DB::table($invoices)->where('id', $row->id)->update([
                        'paid_amount' => $amount,
                        'outstanding_amount' => max(round((float) $row->total_amount - $amount, 4), 0),
                    ]);
                }
            });
    }

    public function down(): void
    {
        //
    }
};

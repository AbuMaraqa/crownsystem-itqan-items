<?php

namespace Modules\Accounting\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * مربعات المجاميع في مطبوعات المستندات التجارية (core::pdf.document يعرضها $totals):
 * الوزن التقريبي والفعلي لسطور الوزن، ثم المبالغ. الخصم والضريبة يظهران فقط حين لا يكونان صفراً،
 * والمجموع الفرعي حين يختلف عن الاجمالي، فالمستند البسيط يُطبع بمربعات قليلة كما في نموذج طلبية البيع.
 */
class PdfTotals
{
    /**
     * @param  Collection<int, Model>  $lines  سطور المستند (تُستبعد سطور الخدمات قبل الاستدعاء)
     * @return list<array{0: string, 1: string}>
     */
    public static function weights(Collection $lines): array
    {
        $weighed = $lines->filter(fn (Model $line) => method_exists($line, 'isWeightPricedLine') && $line->isWeightPricedLine());

        if ($weighed->isEmpty()) {
            return [];
        }

        return [
            [__('Total Approx. Weight'), Number::quantity($weighed->sum(fn (Model $line) => (float) $line->total_weight))],
            [__('Total Actual Weight'), Number::quantity($weighed->sum(fn (Model $line) => (float) $line->actual_weight))],
        ];
    }

    /**
     * @param  string  $discountColumn  عمود خصم المستند العام (order_discount، invoice_discount، quotation_discount ...)
     * @return list<array{0: string, 1: string, 2?: bool}>
     */
    public static function money(Model $document, string $discountColumn, string $discountLabel, string $totalLabel): array
    {
        $amount = fn ($value) => number_format((float) $value, 2);
        $total = (float) $document->total_amount;

        return array_values(array_filter([
            round((float) $document->sub_total, 2) !== round($total, 2) ? [__('Subtotal'), $amount($document->sub_total)] : null,
            (float) $document->items_discount ? [__('Items Discount'), $amount($document->items_discount)] : null,
            (float) $document->{$discountColumn} ? [$discountLabel, $amount($document->{$discountColumn})] : null,
            (float) $document->tax_amount ? [__('Tax').' ('.Number::clean($document->tax_rate, 2).'%)', $amount($document->tax_amount)] : null,
            [$totalLabel, trim($amount($total).' '.$document->currency?->code), true],
        ]));
    }
}

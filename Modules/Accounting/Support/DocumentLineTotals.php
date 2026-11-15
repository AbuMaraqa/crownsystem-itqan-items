<?php

namespace Modules\Accounting\Support;

/**
 * مبالغ سطر المستند ومجاميعه: الاجمالي (الكمية المسعّرة × السعر)، خصم السطر بنسبة او بمبلغ
 * (ويُشتق الآخر منه)، ثم المجموع الفرعي وخصم المستند والضريبة والاجمالي النهائي.
 *
 * الكمية المسعّرة لسطر الوزن هي الوزن الفعلي (WeightPieces::line)، ولغيره الكمية. القيم
 * خارج حدودها تُقصّ هنا؛ الخدمة التي ترفضها تتحقق منها قبل الاستدعاء.
 */
final class DocumentLineTotals
{
    /**
     * @return array{gross: float, discount_mode: string, discount_rate: float, discount_amount: float, line_total: float}
     */
    public static function line(float $quantity, float $unitPrice, string $discountMode = 'rate', float $discountRate = 0, float $discountAmount = 0): array
    {
        $gross = round($quantity * $unitPrice, 4);
        $discountMode = $discountMode === 'amount' ? 'amount' : 'rate';

        if ($discountMode === 'amount') {
            $discountAmount = round(min(max($discountAmount, 0), $gross), 4);
            $discountRate = $gross > 0 ? round(($discountAmount / $gross) * 100, 4) : 0.0;
        } else {
            $discountRate = min(max($discountRate, 0), 100);
            $discountAmount = round($gross * ($discountRate / 100), 4);
        }

        return [
            'gross' => $gross,
            'discount_mode' => $discountMode,
            'discount_rate' => $discountRate,
            'discount_amount' => $discountAmount,
            'line_total' => max(round($gross - $discountAmount, 4), 0),
        ];
    }

    /**
     * مجاميع المستند من سطوره. خصم المستند لا يتجاوز الصافي بعد خصومات السطور.
     *
     * @param  iterable<int, array{gross: float|int|string, discount_amount: float|int|string}>  $lines
     * @return array{sub_total: float, items_discount: float, document_discount: float, tax_amount: float, total_amount: float}
     */
    public static function document(iterable $lines, float $documentDiscount = 0, float $taxRate = 0): array
    {
        $subTotal = 0.0;
        $itemsDiscount = 0.0;

        foreach ($lines as $line) {
            $subTotal += (float) $line['gross'];
            $itemsDiscount += (float) $line['discount_amount'];
        }

        $netBeforeDocumentDiscount = max(round($subTotal - $itemsDiscount, 4), 0);
        $documentDiscount = min(max($documentDiscount, 0), $netBeforeDocumentDiscount);
        $taxableTotal = max(round($netBeforeDocumentDiscount - $documentDiscount, 4), 0);
        $taxAmount = round($taxableTotal * ($taxRate / 100), 4);

        return [
            'sub_total' => round($subTotal, 4),
            'items_discount' => round($itemsDiscount, 4),
            'document_discount' => round($documentDiscount, 4),
            'tax_amount' => $taxAmount,
            'total_amount' => round($taxableTotal + $taxAmount, 4),
        ];
    }

    /**
     * رقم من حقل ادخال: الفواصل والمسافات تُحذف، والفارغ او غير الرقمي يعطي القيمة الافتراضية.
     */
    public static function decimal(mixed $value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        return is_numeric($value) ? round((float) $value, 4) : $default;
    }
}

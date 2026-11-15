<?php

namespace Modules\Items\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

/**
 * التحويل بين وحدة الصنف ووحدته الاساسية. factor: عدد الوحدات الاساسية في الوحدة:
 * الكمية الاساسية = الكمية × المعامل، والسعر الاساسي = السعر ÷ المعامل.
 * كرتونة من 12 بسعر 120 = 12 قطعة بسعر 10.
 *
 * النتائج نصوص عشرية بأربع منازل، تقريبها نصف لأعلى. المعامل الصفر او غير الصالح
 * خطأ (DomainException)، حتى لا يُسعَّر سطر بصفر بصمت.
 */
final class UnitConversion
{
    /**
     * @return array{quantity: string, price: string}
     */
    public static function toBase(mixed $quantity, mixed $unitPrice, mixed $factor): array
    {
        $factor = self::factor($factor);

        return [
            'quantity' => self::scaled(ExactDecimal::of($quantity, 'quantity')->multipliedBy($factor)),
            'price' => (string) ExactDecimal::of($unitPrice, 'price')->dividedBy($factor, ExactDecimal::MONEY_SCALE, RoundingMode::HalfUp),
        ];
    }

    /**
     * @return array{quantity: string, price: string}
     */
    public static function fromBase(mixed $baseQuantity, mixed $basePrice, mixed $factor): array
    {
        $factor = self::factor($factor);

        return [
            'quantity' => (string) ExactDecimal::of($baseQuantity, 'quantity')->dividedBy($factor, ExactDecimal::MONEY_SCALE, RoundingMode::HalfUp),
            'price' => self::scaled(ExactDecimal::of($basePrice, 'price')->multipliedBy($factor)),
        ];
    }

    private static function factor(mixed $factor): BigDecimal
    {
        $factor = ExactDecimal::of($factor, 'unit factor');

        if (! $factor->isPositive()) {
            throw new DomainException("Invalid unit factor: {$factor}");
        }

        return $factor;
    }

    private static function scaled(BigDecimal $value): string
    {
        return (string) $value->toScale(ExactDecimal::MONEY_SCALE, RoundingMode::HalfUp);
    }
}

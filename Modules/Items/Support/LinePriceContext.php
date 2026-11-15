<?php

namespace Modules\Items\Support;

/**
 * سياق تسعير سطر مستند: الطرف المختار وسعر صرف عملة المستند. بلا طرف لا يُعبَّأ سعر،
 * كما في النظام القديم.
 */
final class LinePriceContext
{
    public function __construct(
        public readonly ?int $partyId,
        public readonly float $exchangeRate = 1.0,
    ) {}

    public static function for(mixed $partyId, mixed $exchangeRate = null): self
    {
        $rate = is_numeric($exchangeRate) ? (float) $exchangeRate : 0.0;

        return new self(filled($partyId) ? (int) $partyId : null, $rate > 0 ? $rate : 1.0);
    }
}

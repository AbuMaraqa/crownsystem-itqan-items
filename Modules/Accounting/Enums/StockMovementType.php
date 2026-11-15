<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockMovementType: string implements HasLabel, HasColor
{
    case RECEIPT = 'receipt';
    case DELIVERY = 'delivery';
    case TRANSFER_IN = 'transfer_in';
    case TRANSFER_OUT = 'transfer_out';
    case OPENING = 'opening';
    case ADJUSTMENT_IN = 'adjustment_in';
    case ADJUSTMENT_OUT = 'adjustment_out';
    case REVERSAL = 'reversal';
    case PURCHASE_RETURN = 'purchase_return';
    case SALES_RETURN = 'sales_return';
    case INVOICE_ADJUSTMENT = 'invoice_adjustment';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::RECEIPT => __('Goods Receipt'),
            self::DELIVERY => __('Delivery Note'),
            self::TRANSFER_IN => __('Transfer In'),
            self::TRANSFER_OUT => __('Transfer Out'),
            self::OPENING => __('Opening Balance'),
            self::ADJUSTMENT_IN => __('Stock Surplus'),
            self::ADJUSTMENT_OUT => __('Stock Shortage'),
            self::REVERSAL => __('Reversal'),
            self::PURCHASE_RETURN => __('Purchase Return'),
            self::SALES_RETURN => __('Sales Return'),
            self::INVOICE_ADJUSTMENT => __('Invoice Adjustment'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::RECEIPT, self::TRANSFER_IN, self::OPENING, self::ADJUSTMENT_IN, self::SALES_RETURN => 'success',
            self::DELIVERY, self::TRANSFER_OUT, self::ADJUSTMENT_OUT => 'danger',
            self::REVERSAL, self::INVOICE_ADJUSTMENT => 'gray',
            self::PURCHASE_RETURN => 'danger',
        };
    }
}

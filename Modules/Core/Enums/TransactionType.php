<?php

namespace Modules\Core\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

enum TransactionType: string implements HasLabel, HasColor
{
    case Refund = 'Refund';                       // إرجاع مبالغ للعملاء
    case PrepaymentDeposit = 'PrepaymentDeposit';   // دفعة مقدمة/إضافة رصيد (عام لكن يمكن تخصيصه)
    case OtherIncome = 'OtherIncome';             // دخل متنوع غير مصنف
    case GeneralExpense = 'GeneralExpense';       // مصروفات عامة

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Refund => 'إرجاع مالي',
            self::PrepaymentDeposit => 'دفعة مقدمة / رصيد',
            self::OtherIncome => 'دخل متنوع',
            self::GeneralExpense => 'مصروف عام',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Refund => 'danger',
            self::GeneralExpense => 'warning',

            self::PrepaymentDeposit => 'info',
            self::OtherIncome => 'success',
        };

        // أو يمكنك استخدام أسماء الألوان النصية: 'danger', 'warning', 'primary', 'success'
        /*
        return match ($this) {
            self::Refund => 'danger',
            self::GeneralExpense => 'warning',
            self::PrepaymentDeposit => 'info',
            self::OtherIncome => 'success',
        };
        */
    }
}

<?php

return [
    'name' => 'Accounting',
    'table_prefix' => 'accounting_',

    // توقيت الشركة المحلي: به تُحسب «الايام» في متابعة المخزون واوقات الحركات، لا بتوقيت الخادم.
    'company_timezone' => env('COMPANY_TIMEZONE', 'Asia/Hebron'),
];

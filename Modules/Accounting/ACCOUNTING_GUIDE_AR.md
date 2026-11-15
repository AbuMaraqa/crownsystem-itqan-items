# دليل فهم موديول المحاسبة للمطورين

هذا الملف يشرح موديول المحاسبة بطريقة عملية للمطورين الذين لا يملكون خلفية محاسبية قوية.

الفكرة ليست أن تصبح محاسبا، بل أن تفهم:

- لماذا بنينا هذه الجداول.
- ماذا تعني شجرة الحسابات.
- متى تستخدم كل Service أو Function.
- كيف تسجل أي حركة مالية في النظام بطريقة صحيحة.

## القاعدة الذهبية في المحاسبة

أي حركة مالية يجب أن تكون متوازنة:

```text
مجموع المدين Debit = مجموع الدائن Credit
```

مثال بسيط: زبون دفع 100 نقدا مقابل بيع.

```text
Cash             Debit   100
Sales Revenue   Credit  100
```

معنى ذلك:

- النقدية زادت.
- الإيراد زاد.
- مجموع المدين 100.
- مجموع الدائن 100.

إذا لم يتساو المدين والدائن، القيد خطأ والنظام يمنعه.

## ما هي شجرة الحسابات؟

شجرة الحسابات هي قائمة منظمة لكل الحسابات التي يسجل عليها النظام الحركات المالية.

مثال:

```text
1000 Assets
  1100 Current Assets
    1110 Cash on Hand
    1120 Bank Account
    1130 Accounts Receivable
    1140 Inventory

2000 Liabilities
  2100 Current Liabilities
    2110 Accounts Payable

4000 Revenue
  4100 Sales Revenue

5000 Expenses
  5100 Cost of Goods Sold
```

## الحساب الأب والحساب الطرفي

الحساب الأب يستخدم للتجميع فقط.

مثال:

```text
1000 Assets
1100 Current Assets
```

هذه حسابات تجميعية، لذلك:

```text
is_postable = false
```

يعني لا يجوز تسجيل قيد عليها مباشرة.

أما الحساب الطرفي مثل:

```text
1110 Cash on Hand
1120 Bank Account
4100 Sales Revenue
```

فهذه حسابات يمكن تسجيل القيود عليها:

```text
is_postable = true
```

## أنواع الحسابات

يوجد 5 أنواع رئيسية:

```text
Assets      الأصول
Liabilities الالتزامات
Equity      حقوق الملكية
Revenue     الإيرادات
Expenses    المصروفات
```

## معنى كل نوع

### Assets - الأصول

كل شيء تملكه الشركة أو لها حق فيه.

أمثلة:

```text
Cash
Bank
Accounts Receivable
Inventory
Buildings
Vehicles
```

### Liabilities - الالتزامات

كل شيء على الشركة دفعه للغير.

أمثلة:

```text
Accounts Payable
Loans
VAT Payable
Salaries Payable
```

### Equity - حقوق الملكية

حقوق صاحب الشركة أو الشركاء داخل الشركة.

أمثلة:

```text
Owner Capital
Retained Earnings
Current Year Earnings
```

### Revenue - الإيرادات

دخل الشركة من البيع أو الخدمات.

أمثلة:

```text
Sales Revenue
Service Revenue
Interest Income
```

### Expenses - المصروفات

المبالغ التي تدفعها الشركة لتشغيل النشاط.

أمثلة:

```text
Cost of Goods Sold
Salaries Expense
Rent Expense
Utilities Expense
```

## طبيعة الحساب Normal Balance

كل حساب له طبيعة طبيعية:

```text
Assets      طبيعتها Debit
Expenses    طبيعتها Debit

Liabilities طبيعتها Credit
Equity      طبيعتها Credit
Revenue     طبيعتها Credit
```

هذا لا يعني أن الحساب لا يقبل الطرف الآخر، لكنه يعني أن زيادته الطبيعية تكون بهذا الاتجاه.

مثال:

- زيادة النقدية تكون Debit.
- زيادة المبيعات تكون Credit.
- زيادة المصروف تكون Debit.
- زيادة الدين على الشركة تكون Credit.

## الحسابات العكسية Contra Accounts

بعض الحسابات تكون عكس طبيعة نوعها.

مثال:

```text
Accumulated Depreciation
```

هو ضمن الأصول، لكن طبيعته Credit لأنه يخفض قيمة الأصل.

مثال آخر:

```text
Sales Returns and Allowances
Sales Discounts
```

هي ضمن الإيرادات، لكن طبيعتها Debit لأنها تخفض الإيراد.

لهذا السبب أضفنا `normal_balance` بشكل مستقل عن `type`.

## السنة المالية والفترة المحاسبية

الحركات المالية لا تسجل في فراغ. كل قيد يجب أن يكون داخل سنة وفترة.

مثال:

```text
Fiscal Year: 2026
Accounting Period: June 2026
```

إذا كانت الفترة مغلقة، النظام يمنع الترحيل عليها.

السبب:

- حتى لا تتغير أرقام شهر أو سنة تم إغلاقها.
- حتى تكون التقارير ثابتة.

## القيد اليومي Journal Entry

أي حركة مالية في النظام تتحول إلى قيد يومي.

القيد يتكون من:

```text
JournalEntry
JournalLine[]
```

`JournalEntry` هو رأس القيد ويحتوي على:

```text
entry_number
entry_date
status
reference_no
description
fiscal_year_id
accounting_period_id
```

`JournalLine` هو سطر القيد ويحتوي على:

```text
account_id
branch_id
currency_id
exchange_rate
debit
credit
debit_base
credit_base
description
```

## حالات القيد

القيد له 3 حالات:

```text
Draft   مسودة
Posted  مرحل
Voided  ملغى
```

### Draft

قيد غير معتمد بعد.

يمكن تعديله أو حذفه.

### Posted

قيد تم اعتماده وترحيله.

هذا القيد أثر على الأرصدة والتقارير.

لا يجوز تعديله مباشرة.

### Voided

قيد تم إلغاؤه بطريقة محاسبية.

الإلغاء لا يعني حذف القيد، بل إنشاء قيد عكسي.

## لماذا لا نحذف قيد Posted؟

لأن القيد المرحل أثر على التقارير والأرصدة.

لو حذفناه مباشرة، سنفقد الأثر التاريخي.

الطريقة الصحيحة هي قيد عكسي.

مثال:

القيد الأصلي:

```text
Cash             Debit   100
Sales Revenue   Credit  100
```

القيد العكسي:

```text
Sales Revenue   Debit   100
Cash            Credit  100
```

هكذا يصبح الأثر النهائي صفر، لكن التاريخ يبقى محفوظا.

## AccountService

الملف:

```text
Modules/Accounting/Services/AccountService.php
```

هذا Service مسؤول عن إنشاء وتعديل الحسابات داخل شجرة الحسابات.

لا يفضل استخدام:

```php
Account::create()
```

مباشرة من أماكن متعددة.

الأفضل استخدام:

```php
app(AccountService::class)
```

لأن Service يحفظ البيانات والترجمة داخل Transaction.

### create(array $data)

تستخدم لإنشاء حساب جديد.

مثال:

```php
app(AccountService::class)->create([
    'code' => '1125',
    'name' => 'Arab Bank',
    'parent_id' => $bankParentId,
    'type' => AccountType::ASSET->value(),
    'is_postable' => true,
]);
```

متى تستخدمها؟

- عند إضافة حساب من لوحة التحكم.
- عند إنشاء حساب من Seeder.
- عند إضافة حساب فرعي جديد.

### update(Account $account, array $data)

تستخدم لتعديل حساب موجود.

مثال:

```php
app(AccountService::class)->update($account, [
    'code' => '1125',
    'name' => 'Arab Bank - Main Account',
    'type' => AccountType::ASSET->value(),
    'is_postable' => true,
]);
```

متى تستخدمها؟

- عند تعديل اسم الحساب.
- عند نقل الحساب تحت أب آخر.
- عند تغيير حالة الحساب.

### nextChildCode(?int $parentId, $type = null)

تستخدم لاقتراح كود الحساب التالي.

مثال:

لو عندك حساب أب:

```text
1120 Bank Account
```

وأردت إضافة حساب فرعي تحته، يمكن استخدام:

```php
$code = app(AccountService::class)->nextChildCode($parentId);
```

قد يرجع:

```text
112001
```

متى تستخدمها؟

- عند الضغط على زر Add Child Account.
- عند بناء شاشة إضافة حساب فرعي.
- في أي مكان تريد اقتراح كود بدل إدخاله يدويا.

## JournalEntryService

الملف:

```text
Modules/Accounting/Services/JournalEntryService.php
```

هذا أهم Service في المحاسبة.

أي حركة مالية يجب أن تمر من هنا.

لا يفضل إنشاء قيود وسطور وأرصدة يدويا باستخدام:

```php
JournalEntry::create()
JournalLine::create()
AccountBalance::create()
```

الأفضل استخدام `JournalEntryService` لأنه يحتوي على:

- Transaction.
- فحص توازن القيد.
- فحص الفترة المفتوحة.
- فحص الحسابات القابلة للترحيل.
- تحديث الأرصدة.
- إنشاء قيد عكسي عند الإلغاء.

### createDraft(array $data, array $lines)

ينشئ قيد مسودة.

مثال بيع نقدي:

```php
app(JournalEntryService::class)->createDraft([
    'entry_date' => now()->toDateString(),
    'reference_no' => 'INV-1001',
    'source_module' => 'Items',
    'source_type' => 'Invoice',
], [
    [
        'account_id' => $cashAccountId,
        'currency_id' => $currencyId,
        'exchange_rate' => 1,
        'debit' => 100,
        'credit' => 0,
    ],
    [
        'account_id' => $salesAccountId,
        'currency_id' => $currencyId,
        'exchange_rate' => 1,
        'debit' => 0,
        'credit' => 100,
    ],
]);
```

متى تستخدمها؟

- عند إنشاء فاتورة.
- عند تسجيل دفعة.
- عند تسجيل مصروف.
- عند تسجيل شراء.
- عند تسجيل أي حركة مالية قبل اعتمادها.

### updateDraft(JournalEntry $entry, array $data, array $lines)

يعدل قيد مسودة.

لا يعمل إذا القيد `Posted`.

متى تستخدمها؟

- عندما المستخدم يعدل القيد قبل ترحيله.
- عندما فاتورة غير معتمدة تتغير بياناتها.

### post(JournalEntry $entry)

يرحل القيد.

عند الترحيل يحدث التالي:

1. يتأكد أن القيد Draft.
2. يتأكد أن الفترة مفتوحة.
3. يتأكد أن المدين يساوي الدائن.
4. يتأكد أن كل الحسابات `is_postable = true`.
5. يغير الحالة إلى Posted.
6. يحدث جدول `account_balances`.

مثال:

```php
$entry = app(JournalEntryService::class)->createDraft($data, $lines);

app(JournalEntryService::class)->post($entry);
```

متى تستخدمها؟

- عند اعتماد فاتورة.
- عند اعتماد سند قبض.
- عند اعتماد سند صرف.
- عند اعتماد قيد يدوي.

### void(JournalEntry $entry, ?string $description = null)

يلغي قيد مرحل بعمل قيد عكسي.

مثال:

```php
app(JournalEntryService::class)->void($entry, 'Invoice cancelled');
```

متى تستخدمها؟

- عند إلغاء فاتورة تم ترحيلها.
- عند إلغاء سند قبض مرحل.
- عند تصحيح قيد بدون حذف الأثر التاريخي.

### nextEntryNumber()

يرجع رقم قيد تلقائي.

مثال:

```php
$number = app(JournalEntryService::class)->nextEntryNumber();
```

قد يرجع:

```text
JE-202606-00001
```

متى تستخدمها؟

- إذا أردت عرض رقم القيد قبل الحفظ.
- أو ترك `createDraft` يولده تلقائيا.

### assertBalanced(JournalEntry $entry)

يتأكد أن القيد متوازن.

غالبا لا تحتاج استخدامها يدويا لأن `createDraft` و`post` يستخدمانها.

متى تستخدمها؟

- في tests.
- في أدوات فحص داخلية.
- قبل عرض رسالة للمستخدم أن القيد جاهز للترحيل.

## AccountingReportService

الملف:

```text
Modules/Accounting/Services/AccountingReportService.php
```

هذا Service للتقارير.

### trialBalance(?string $from = null, ?string $to = null)

يرجع ميزان المراجعة.

ميزان المراجعة يعني:

```text
كل حساب
مجموع المدين
مجموع الدائن
الرصيد
```

مثال:

```php
$rows = app(AccountingReportService::class)
    ->trialBalance('2026-01-01', '2026-12-31');
```

متى تستخدمها؟

- صفحة Trial Balance.
- API تقارير.
- فحص سريع أن القيود متوازنة.

### accountTypeSummary(AccountType $type, ?string $from = null, ?string $to = null)

يرجع ملخص حسابات نوع معين.

مثال لجلب الأصول:

```php
$assets = app(AccountingReportService::class)
    ->accountTypeSummary(AccountType::ASSET);
```

مثال لجلب الإيرادات:

```php
$revenues = app(AccountingReportService::class)
    ->accountTypeSummary(AccountType::REVENUE, $from, $to);
```

متى تستخدمها؟

- Balance Sheet.
- Income Statement.
- أي تقرير يريد نوع حساب محدد.

## Account Model Functions

الملف:

```text
Modules/Accounting/Entities/Account.php
```

### normalBalanceForType($type)

ترجع الطبيعة الافتراضية لنوع الحساب.

مثال:

```php
Account::normalBalanceForType(AccountType::ASSET);
```

ترجع:

```text
Debit
```

متى تستخدمها؟

- عند إنشاء حساب جديد.
- عند تغيير نوع الحساب.
- عند تعبئة `normal_balance` تلقائيا.

### getTreeData()

ترجع شجرة الحسابات كاملة كمصفوفة.

متى تستخدمها؟

- API tree endpoint.
- أي واجهة تحتاج شجرة كاملة.

### options(bool $postableOnly = false)

ترجع الحسابات بصيغة مناسبة للـ select.

مثال:

```php
Account::options()
```

ترجع كل الحسابات.

مثال:

```php
Account::options(true)
```

ترجع فقط الحسابات القابلة للترحيل.

متى تستخدمها؟

- Select الحساب في سطر القيد.
- Select الحساب في Account Mapping.
- أي dropdown لاختيار حساب.

### refreshHierarchy()

تحدث:

```text
level
path
```

للحساب.

متى تستخدمها؟

- بعد إنشاء حساب في Seeder.
- بعد نقل حساب تحت أب جديد.
- نادرا تحتاجها يدويا لأن Model events تعالجها غالبا.

### refreshDescendantPaths()

تحدث مسارات الأبناء إذا تغير مكان الحساب الأب.

متى تستخدمها؟

- عند تغيير `parent_id` لحساب له أبناء.

## FiscalYear Functions

الملف:

```text
Modules/Accounting/Entities/FiscalYear.php
```

### close()

يغلق السنة المالية وفتراتها.

مثال:

```php
$fiscalYear->close();
```

متى تستخدمها؟

- عند انتهاء السنة المالية.
- عندما تريد منع الترحيل على كل فترات السنة.

### reopen()

يعيد فتح السنة المالية وفتراتها.

مثال:

```php
$fiscalYear->reopen();
```

متى تستخدمها؟

- إذا تم الإغلاق بالخطأ.
- إذا احتجت تعديل قيود قبل الإقفال النهائي.

## AccountingPeriod Functions

الملف:

```text
Modules/Accounting/Entities/AccountingPeriod.php
```

### scopeOpen()

يجلب الفترات المفتوحة.

مثال:

```php
AccountingPeriod::query()->open()->get();
```

### scopeContainsDate($date)

يجلب الفترة التي تحتوي تاريخا معينا.

مثال:

```php
AccountingPeriod::query()
    ->containsDate('2026-06-14')
    ->first();
```

يستخدم داخليا عند إنشاء أو تعديل قيد.

### isClosed()

يفحص هل الفترة مغلقة.

مثال:

```php
if ($period->isClosed()) {
    // امنع الترحيل
}
```

## أمثلة عملية للحركات المالية

### 1. بيع نقدي

الزبون دفع نقدا.

```text
Debit  Cash
Credit Sales Revenue
```

مثال:

```php
app(JournalEntryService::class)->createDraft($data, [
    ['account_id' => $cashId, 'currency_id' => $currencyId, 'exchange_rate' => 1, 'debit' => 100, 'credit' => 0],
    ['account_id' => $salesId, 'currency_id' => $currencyId, 'exchange_rate' => 1, 'debit' => 0, 'credit' => 100],
]);
```

### 2. بيع آجل

الزبون لم يدفع الآن.

```text
Debit  Accounts Receivable
Credit Sales Revenue
```

يعني صار لنا دين على الزبون.

### 3. تحصيل من زبون

الزبون دفع دين سابق.

```text
Debit  Cash / Bank
Credit Accounts Receivable
```

### 4. شراء مخزون نقدا

```text
Debit  Inventory
Credit Cash / Bank
```

### 5. شراء مخزون آجل

```text
Debit  Inventory
Credit Accounts Payable
```

يعني صار علينا دين للمورد.

### 6. دفع لمورد

```text
Debit  Accounts Payable
Credit Cash / Bank
```

### 7. تسجيل مصروف إيجار

```text
Debit  Rent Expense
Credit Cash / Bank
```

### 8. راتب مستحق ولم يدفع بعد

```text
Debit  Salaries Expense
Credit Salaries Payable
```

### 9. دفع الراتب المستحق

```text
Debit  Salaries Payable
Credit Cash / Bank
```

### 10. إلغاء فاتورة بيع مرحلة

لا تحذف القيد.

استخدم:

```php
app(JournalEntryService::class)->void($entry);
```

النظام سينشئ قيدا عكسيا.

## AccountMapping

الهدف من `account_mappings` هو ربط الموديولات بالحسابات بدون hard-code.

مثال:

```text
Items cash -> 1110
Items bank -> 1120
Items accounts_receivable -> 1130
Items inventory -> 1140
Items accounts_payable -> 2110
Items sales_revenue -> 4100
Items cost_of_goods_sold -> 5100
```

متى تستخدمه؟

- موديول المبيعات يحتاج يعرف حساب المبيعات.
- موديول المخزون يحتاج يعرف حساب المخزون.
- موديول الدفع يحتاج يعرف حساب النقد أو البنك.

بدل أن تكتب:

```php
$salesAccount = Account::where('code', '4100')->first();
```

الأفضل لاحقا:

```php
$mapping = AccountMapping::query()
    ->where('source_module', 'Items')
    ->where('source_key', 'sales_revenue')
    ->first();
```

## متى أستخدم كل Service؟

### عندما أريد إنشاء أو تعديل حساب

استخدم:

```text
AccountService
```

### عندما أريد تسجيل حركة مالية

استخدم:

```text
JournalEntryService
```

### عندما أريد تقريرا

استخدم:

```text
AccountingReportService
```

### عندما أريد إغلاق فترة أو سنة

استخدم:

```text
FiscalYear
AccountingPeriod
```

### عندما أريد اختيار حساب داخل Select

استخدم:

```php
Account::options(true)
```

## أشياء لا تعملها

لا تنشئ قيد مرحل مباشرة.

لا تعمل:

```php
JournalEntry::create(['status' => JournalEntryStatus::POSTED]);
```

استخدم:

```php
$entry = app(JournalEntryService::class)->createDraft($data, $lines);
app(JournalEntryService::class)->post($entry);
```

لا تعدل قيد Posted مباشرة.

استخدم:

```php
app(JournalEntryService::class)->void($entry);
```

لا تسجل على حساب أب.

استخدم فقط حساب:

```text
is_postable = true
```

لا تتجاهل العملة وسعر الصرف.

كل line يجب أن يحتوي على:

```text
currency_id
exchange_rate
```

## تسلسل عمل موديول مستقبلي مع المحاسبة

مثال: فاتورة مبيعات.

1. المستخدم ينشئ الفاتورة.
2. النظام يحدد الحسابات من `account_mappings`.
3. النظام ينشئ قيد Draft.
4. إذا الفاتورة اعتمدت، النظام يعمل Post.
5. يظهر أثرها في Trial Balance وIncome Statement.

مثال عام:

```php
$entry = app(JournalEntryService::class)->createDraft([
    'entry_date' => $invoice->date,
    'reference_no' => $invoice->number,
    'source_module' => 'Items',
    'source_type' => 'Invoice',
    'sourceable_id' => $invoice->id,
    'sourceable_type' => $invoice::class,
], $lines);

app(JournalEntryService::class)->post($entry);
```

## كيف أفكر كمطور؟

اسأل نفسك دائما:

1. هل هذه العملية لها أثر مالي؟
2. ما الحساب الذي زاد؟
3. ما الحساب الذي نقص أو ما المقابل؟
4. هل المدين يساوي الدائن؟
5. هل الفترة مفتوحة؟
6. هل الحسابات قابلة للترحيل؟

إذا كانت العملية مالية، غالبا تحتاج `JournalEntryService`.

## خلاصة سريعة

```text
AccountService
لإنشاء وتعديل شجرة الحسابات.

JournalEntryService
لإنشاء وترحيل وإلغاء القيود.

AccountingReportService
للتقارير.

FiscalYear / AccountingPeriod
لإدارة الإغلاق والفترات.

Account::options(true)
لاختيار الحسابات التي يمكن الترحيل عليها.

AccountMapping
لربط الموديولات المستقبلية بالحسابات.
```

أهم قاعدة:

```text
أي حركة مالية = قيد يومية متوازن
```

وأهم حماية:

```text
لا تعدل قيد Posted، بل اعمل Void بقيد عكسي.
```

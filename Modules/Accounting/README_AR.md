# توثيق موديول المحاسبة وشجرة الحسابات

هذا الملف يشرح العمل الذي تم تنفيذه داخل موديول `Accounting` حتى يكون مرجعا للمطورين الذين سيعملون على المشروع لاحقا.

## الهدف العام

تم بناء نواة محاسبية داخل المشروع بطريقة قريبة من أسلوب الموديولات الموجودة مثل `Core`, `Items`, `Branch`, و`GeoLocation`.

الهدف هو توفير أساس قابل للتوسع لبرنامج محاسبة كامل يحتوي على:

- شجرة حسابات احترافية.
- سنوات مالية وفترات محاسبية.
- قيود يومية بنظام القيد المزدوج.
- أرصدة حسابات.
- تقارير محاسبية أولية.
- صلاحيات وأدوار.
- Sidebar.
- API.
- ترجمة عربية.
- Tests.

## مكان الموديول

تم إنشاء الموديول هنا:

```text
Modules/Accounting
```

وتم تفعيله داخل:

```text
modules_statuses.json
```

## بنية الموديول

الموديول يحتوي على نفس نمط المشروع:

```text
Modules/Accounting/config
Modules/Accounting/Providers
Modules/Accounting/routes
Modules/Accounting/Livewire
Modules/Accounting/resources/views
Modules/Accounting/Lang
Modules/Accounting/database/migrations
Modules/Accounting/database/seeders
Modules/Accounting/Entities
Modules/Accounting/Enums
Modules/Accounting/Services
Modules/Accounting/Http/Controllers
Modules/Accounting/Transformers
Modules/Accounting/tests
```

## الجداول

تم إنشاء جداول prefix لها `accounting_`:

```text
accounting_accounts
accounting_account_translations
accounting_fiscal_years
accounting_accounting_periods
accounting_journal_entries
accounting_journal_lines
accounting_account_balances
accounting_account_mappings
```

كل الجداول الأساسية تحتوي على:

- `created_by`
- `softDeletes`
- `timestamps`
- indexes مختصرة الأسماء حتى لا يظهر خطأ MySQL الخاص بطول اسم الـ index.

مثال على المشكلة التي تم تجنبها:

```text
Identifier name is too long
```

لذلك تم استخدام أسماء قصيرة مثل:

```text
acct_je_fy_period_idx
acct_je_status_date_idx
acct_accounts_fast_lookup_idx
```

## Enums

تم بناء Enums رقمية `int` بدل strings لأن المشروع يفضل هذا الأسلوب ولأنها أخف في التخزين والاستعلام.

الملفات:

```text
Modules/Accounting/Enums/AccountType.php
Modules/Accounting/Enums/NormalBalance.php
Modules/Accounting/Enums/JournalEntryStatus.php
Modules/Accounting/Enums/AccountingPeriodStatus.php
```

القيم الأساسية:

```text
AccountType:
1 = Asset
2 = Liability
3 = Equity
4 = Revenue
5 = Expense

NormalBalance:
1 = Debit
2 = Credit

JournalEntryStatus:
1 = Draft
2 = Posted
3 = Voided

AccountingPeriodStatus:
1 = Open
2 = Closed
```

## Models

تم بناء Models داخل:

```text
Modules/Accounting/Entities
```

وهي:

```text
Account
AccountTranslation
FiscalYear
AccountingPeriod
JournalEntry
JournalLine
AccountBalance
AccountMapping
```

تم إضافة `LogsActivity` لكل Models حتى يتم تسجيل التعديلات بنفس أسلوب المشروع.

تم إضافة `SoftDeletes` للنماذج الأساسية.

## الترجمة

تم استخدام package الموجود في المشروع:

```text
astrotomic/laravel-translatable
```

موديل الحساب `Account` يستخدم:

```php
use Astrotomic\Translatable\Translatable;
```

والحقول المترجمة:

```text
name
description
```

وتم إنشاء model خاص بالترجمة:

```text
AccountTranslation
```

وجدول:

```text
accounting_account_translations
```

## شجرة الحسابات

تم بناء شجرة الحسابات باستخدام الحقول:

```text
parent_id
code
level
path
sort_order
is_postable
is_active
```

الشجرة مشتركة على مستوى النظام ولا تحتوي على `branch_id`.

سبب ذلك:

- شجرة الحسابات يجب أن تكون موحدة.
- التفصيل حسب الفرع يتم على القيود والأرصدة.
- `branch_id` موجود في `journal_lines` و`account_balances`.

## قواعد شجرة الحسابات

تمت إضافة الحمايات التالية:

- منع الحساب أن يكون أب لنفسه.
- منع إنشاء دورة داخل الشجرة.
- منع حذف حساب له أبناء.
- منع حذف حساب عليه قيود أو أرصدة حتى لو كانت SoftDeleted.
- الحسابات الأب غير قابلة للترحيل.
- الترحيل يتم فقط على الحسابات الطرفية التي يكون فيها `is_postable = true`.

## واجهة شجرة الحسابات

تم استبدال جدول الحسابات بواجهة Tree مخصصة باستخدام Livewire.

الملفات:

```text
Modules/Accounting/Livewire/Pages/Account/Index.php
Modules/Accounting/resources/views/livewire/pages/account/index.blade.php
Modules/Accounting/resources/views/livewire/pages/account/partials/tree-node.blade.php
```

الواجهة تحتوي على:

- فتح وإغلاق الحسابات `Expand / Collapse`.
- فتح الكل `Expand All`.
- إغلاق الكل `Collapse All`.
- بحث بالكود أو الاسم.
- فلترة حسب نوع الحساب.
- فلترة الحسابات القابلة للترحيل فقط.
- فلترة الحسابات النشطة فقط.
- زر إضافة حساب رئيسي.
- زر إضافة حساب فرعي.
- زر تعديل.
- زر حذف.

كل الأزرار مربوطة بالصلاحيات.

زر إضافة حساب فرعي يرسل `parent_id` إلى صفحة الإضافة، وصفحة الإضافة تقوم بتعبئة:

- الحساب الأب.
- نوع الحساب.
- طبيعة الحساب.
- الكود المقترح التالي.

## السنوات المالية والفترات

تم بناء:

```text
FiscalYear
AccountingPeriod
```

السنة المالية تحتوي على:

- الاسم.
- تاريخ البداية.
- تاريخ النهاية.
- الحالة.
- هل هي الافتراضية.

الفترة المحاسبية تحتوي على:

- السنة المالية.
- الاسم.
- تاريخ البداية.
- تاريخ النهاية.
- الحالة.

الحالات:

```text
Open
Closed
```

عند إغلاق السنة المالية يتم إغلاق الفترات التابعة.

عند إعادة فتح السنة المالية يتم إعادة فتح الفترات التابعة.

## القيود اليومية

تم بناء نظام قيود يومية مبدئي بطريقة القيد المزدوج.

الجداول:

```text
accounting_journal_entries
accounting_journal_lines
```

القيد الرئيسي `JournalEntry` يحتوي على:

- رقم القيد.
- السنة المالية.
- الفترة المحاسبية.
- تاريخ القيد.
- الحالة.
- رقم المرجع.
- المصدر.
- الوصف.
- بيانات الترحيل.
- المستخدم المنشئ.

سطور القيد `JournalLine` تحتوي على:

- الحساب.
- الفرع.
- العملة.
- سعر الصرف.
- مدين.
- دائن.
- مدين بعملة الأساس.
- دائن بعملة الأساس.
- الوصف.

## حالات القيود

القيد له ثلاث حالات:

```text
Draft
Posted
Voided
```

القواعد:

- يمكن تعديل القيد فقط وهو Draft.
- يمكن ترحيل القيد فقط وهو Draft.
- لا يمكن تعديل قيد Posted.
- لا يتم حذف أو تعديل قيد Posted مباشرة.
- إلغاء القيد يتم بعمل قيد عكسي مرتبط بالقيد الأصلي.

## خدمة القيود

المنطق الرئيسي موجود في:

```text
Modules/Accounting/Services/JournalEntryService.php
```

الخدمة مسؤولة عن:

- إنشاء قيد Draft.
- تعديل قيد Draft.
- التحقق من توازن القيد.
- التحقق من أن الحسابات قابلة للترحيل.
- ترحيل القيد.
- تحديث الأرصدة.
- إنشاء قيد عكسي عند الإلغاء.
- منع الترحيل على فترة مغلقة.

## الأرصدة

تم إنشاء جدول:

```text
accounting_account_balances
```

الأرصدة مرتبطة بـ:

- الحساب.
- الفرع.
- العملة.
- السنة المالية.
- الفترة المحاسبية.

هذا يسمح بأن تبقى شجرة الحسابات مشتركة، بينما تكون الأرصدة مفصلة حسب الفرع.

## العملات

تم استخدام `Currency` الموجود في `Core`.

كل سطر قيد يحتوي على:

- `currency_id`
- `exchange_rate`
- `debit`
- `credit`
- `debit_base`
- `credit_base`

بهذا يمكن لاحقا دعم قيود بعملات مختلفة مع تقارير بعملة الأساس.

## التقارير

تم بناء خدمة:

```text
Modules/Accounting/Services/AccountingReportService.php
```

والتقارير الحالية:

```text
Trial Balance
Ledger
Balance Sheet
Income Statement
```

تم تحسين الاستعلامات:

- استخدام `JOIN` بدل `whereHas` في التقارير الثقيلة.
- استخدام `where` بدل `whereDate` حتى يستفيد MySQL من indexes.
- بناء الشجرة من query واحدة ثم ترتيبها في الذاكرة.

## Account Mappings

تم بناء جدول:

```text
accounting_account_mappings
```

الهدف منه ربط الموديولات المستقبلية بالحسابات.

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

هذا يسمح بأن تبقى القيود المستقبلية قابلة للتوليد من المبيعات، المشتريات، المخزون، الرواتب، أو أي موديول آخر.

## Routes

تم بناء Web routes تحت:

```text
/admin/accounting
```

الأسماء:

```text
accounting.accounts.index
accounting.accounts.add
accounting.accounts.edit

accounting.journal-entries.index
accounting.journal-entries.add
accounting.journal-entries.edit

accounting.fiscal-years.index
accounting.account-mappings.index

accounting.reports.trial-balance
accounting.reports.ledger
accounting.reports.balance-sheet
accounting.reports.income-statement
```

الملف:

```text
Modules/Accounting/routes/web.php
```

## API

تم بناء API محمي بـ Sanctum:

```text
/api/v1/accounting/accounts
/api/v1/accounting/accounts/tree
/api/v1/accounting/journal-entries
/api/v1/accounting/reports/trial-balance
```

تم استخدام:

```text
spatie/laravel-query-builder
```

للـ filters والـ sorts.

الملفات:

```text
Modules/Accounting/routes/api.php
Modules/Accounting/Http/Controllers/Api/V1
Modules/Accounting/Transformers/V1
```

## الصلاحيات

تم إنشاء الصلاحيات التالية:

```text
Account View List
Account Create
Account Update
Account Delete
Account View
Account Info

Journal Entry View List
Journal Entry Create
Journal Entry Update
Journal Entry Delete
Journal Entry View
Journal Entry Info
Journal Entry Post
Journal Entry Void

Fiscal Year View List
Fiscal Year Create
Fiscal Year Update
Fiscal Year View
Fiscal Year Info
Fiscal Year Close
Fiscal Year Reopen

Accounting Mapping View List
Accounting Mapping Update

Accounting Report View List
Accounting Report Export
```

وتم تعديل صفحة Roles حتى تفهم الأفعال الجديدة:

```text
Post
Void
Close
Reopen
Export
```

## Sidebar

تمت إضافة مجموعة:

```text
Accounting
```

وتحتها:

```text
Chart of Accounts
Journal Entries
Fiscal Years
Account Mappings
Trial Balance
Ledger
Balance Sheet
Income Statement
```

تم أيضا إصلاح تسجيل `SidebarService` حتى لا يتم إعادة تهيئته من كل موديول.

الفكرة:

- `Core` يسجل الخدمة.
- باقي الموديولات تضيف عناصرها فقط.

## ملف الترجمة العربي

تم إنشاء:

```text
Modules/Accounting/Lang/ar.json
```

ويحتوي على:

- أسماء الصفحات.
- أسماء الأزرار.
- رسائل النجاح.
- رسائل الخطأ.
- أسماء الصلاحيات.
- Labels الشجرة.
- Labels التقارير.

## Seeder شجرة الحسابات

تم توسيع:

```text
Modules/Accounting/database/seeders/DefaultChartOfAccountsSeeder.php
```

إلى شجرة حسابات عالمية من 111 حساب.

الشجرة تشمل:

- الأصول.
- الأصول المتداولة.
- النقدية والبنوك.
- الذمم المدينة.
- المخزون.
- المصروفات المدفوعة مقدما.
- الأصول الثابتة.
- مجمع الإهلاك.
- الأصول غير الملموسة.
- الالتزامات.
- الذمم الدائنة.
- الضرائب.
- الرواتب المستحقة.
- القروض.
- الالتزامات غير المتداولة.
- حقوق الملكية.
- رأس المال.
- الأرباح المحتجزة.
- الاحتياطيات.
- الإيرادات.
- مردودات وخصومات المبيعات.
- الإيرادات الأخرى.
- تكلفة البضاعة المباعة.
- المشتريات.
- مصروفات التشغيل.
- مصروفات البيع والتوزيع.
- المصروفات الإدارية.
- المصروفات التمويلية.
- المصروفات الأخرى.

تم الحفاظ على الأكواد المهمة المستخدمة في mappings:

```text
1110 Cash on Hand
1120 Bank Account
1130 Accounts Receivable
1140 Inventory
2110 Accounts Payable
4100 Sales Revenue
5100 Cost of Goods Sold
```

## الحسابات العكسية

تم ضبط بعض الحسابات بطبيعة مختلفة عن نوعها الطبيعي.

أمثلة:

```text
Allowance for Doubtful Accounts
Accumulated Depreciation
Sales Returns and Allowances
Sales Discounts
Purchase Returns and Allowances
Owner Drawings / Dividends
```

هذا مهم لأن بعض الحسابات تكون Contra Accounts.

## Tests

تم إضافة اختبارات داخل:

```text
Modules/Accounting/tests
```

الاختبارات تغطي:

- توليد أكواد الحسابات.
- منع الدورات في الشجرة.
- منع حذف حساب له أبناء.
- منع إنشاء قيد غير متوازن.
- ترحيل قيد متوازن.
- تحديث الأرصدة بعد الترحيل.
- منع الترحيل على فترة مغلقة.
- API tree endpoint.
- API trial balance endpoint.
- Idempotent seeders.

تم تعديل:

```text
phpunit.xml
```

حتى يقرأ:

```text
Modules/*/tests
```

## تحسينات الأداء

تم عمل عدة نقاط لتحسين الأداء:

- Enums رقمية.
- indexes مركبة على الجداول الثقيلة.
- أسماء indexes قصيرة.
- بناء الشجرة من query واحدة.
- عدم استخدام recursive eager loading في Tree UI.
- استخدام `JOIN` في تقارير Trial Balance وLedger.
- تجنب `whereDate` على أعمدة التاريخ.
- تخزين `level` و`path` لتسريع العرض والفلاتر المستقبلية.

## البكجات المقترحة لاحقا

لم يتم تركيب package خارجي للشجرة لأن custom Livewire مناسب أكثر للمشروع حاليا.

لكن لو احتجنا recursive queries متقدمة لاحقا، أفضل خيار:

```text
staudenmeir/laravel-adjacency-list
```

الرابط:

```text
https://github.com/staudenmeir/laravel-adjacency-list
```

## ملاحظات مهمة

- شجرة الحسابات لا تحتوي على `branch_id`.
- الفروع تظهر في القيود والأرصدة.
- لا يوجد `tenant_id` أو `company_id` حاليا.
- يمكن إضافة tenancy لاحقا بدون تغيير جذري في شجرة الحسابات.
- `CoreTransaction` لم يتم استبداله، ويبقى كـ legacy أو سجل مالي مبسط.
- الربط المستقبلي بين الموديولات والمحاسبة يتم عبر `account_mappings`.

## التحقق الذي تم

تم تشغيل الفحوصات المتاحة:

```text
PHP lint
JSON validation
git diff --check
```

وكلها نجحت.

لم يتم تشغيل:

```text
php artisan test
php artisan route:list
```

بسبب أن نسخة PHP في XAMPP هي:

```text
8.2.12
```

بينما dependencies في المشروع تتطلب:

```text
PHP >= 8.3.0
```

## الخطوات المقترحة بعد تحديث PHP

بعد تشغيل المشروع على PHP 8.3 أو أعلى، ينصح بتشغيل:

```bash
php artisan migrate
php artisan db:seed --class=Modules\\Accounting\\database\\seeders\\AccountingDatabaseSeeder
php artisan route:list --name=accounting
php artisan test --filter=Accounting
```

## خلاصة

الموديول أصبح يحتوي على نواة محاسبية قابلة للتوسع.

أهم ما تم إنجازه:

- شجرة حسابات عالمية.
- واجهة Tree احترافية.
- قيود يومية بنظام القيد المزدوج.
- سنوات وفترات مالية.
- أرصدة.
- تقارير.
- API.
- صلاحيات.
- ترجمة.
- Seeders.
- Tests.

هذا الأساس مناسب لأن يعمل عليه أكثر من مطور لاحقا بدون تضارب كبير، بشرط الالتزام بنفس نمط الموديول والخدمات والصلاحيات.

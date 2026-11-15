# الموجة 2: المستند المرحَّل نهائي (R‑01) — خطة التنفيذ

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**الهدف:** إزالة كل مسار يُلغي أو يُبطل مستندًا مرحَّلًا، وإحلال مستند معاكس جديد محلّه، يبقى معه الأصل «مرحَّلًا».

**ما تضيفه الخطة مع ذلك:**
- فصل حساب العميل عن حساب المورد للطرف الواحد.
- جهة الحساب في السندات.
- الإشعارات الدائنة والمدينة.
- حركة إعادة تقييم للمخزون.

**المعمارية:**
- كل مستند معاكس يُبنى بالمسار القائم نفسه: Service، ثم `JournalEntryService` و`StockLedgerService`، ثم `PaymentAllocationService`.
- الاختصارات («مرتجع كامل»، «سند تصحيح»، «تسوية معاكسة»، «قيد عكسي») تُنشئ **مسودة** يراجعها المستخدم ثم يرحّلها.
- لا تُستخدم `void()` إلا في فك تخصيص له قيد فرق صرف.

**التقنيات:** Laravel 12، nwidart modules، Livewire 3، Filament 3، Spatie Permission، PHPUnit على MySQL.

**التصميم:** [القسمان 2 و4 من التصميم](../specs/2026-10-28-accounting-audit-remediation-design.md)، أي القرارات C‑1 وC‑4 وC‑5 وC‑6 وC‑8. **يعتمد على:** [الموجتين 0 و1](2026-10-28-wave-0-1-stabilize-and-critical.md).

## القيود العامة

- كل القيود العامة في خطة الموجتين 0 و1 تسري هنا.
- القاعدة R‑01: لا تبقى في `Modules/*/Services` ولا في `Modules/*/Livewire` دالة عامة تنقل مستندًا مرحَّلًا إلى «ملغى» أو «مُبطَل». حالات `CANCELLED` و`VOIDED` تبقى في الـ enums دون استعمال جديد.
- الالتزامات غير المرحَّلة يبقى إلغاؤها مسموحًا: عروض الأسعار، وأوامر البيع، وطلبات الشراء، وأوامر الإنتاج.
- المستند المعاكس يستخدم سعر صرف الأصل (C‑6).
- التاريخ الافتراضي للمستند المعاكس هو اليوم، ويختار المستخدم غيره في فترة مفتوحة. تاريخ حركة المخزون = تاريخ قيده دائمًا.
- الجداول الجديدة تتبع قسم «Migrations» في مهارة laravel: البادئة `accounting_`، وأسماء FK قصيرة، والدقة (18,4) و(20,6) و(18,8)، وسطر تعليق عربي في الأعلى.
- الصلاحيات الجديدة تُسجَّل في `Modules/Accounting/Support/LedgerPermissions.php`، وmigration جديدة تستدعي `LedgerPermissions::sync()`، وتُحدَّث خرائط `Modules/Core/Livewire/Pages/Roles/Index.php`.
- لا يُشغَّل migrate على قاعدة التطوير. الاختبارات تستخدم `RefreshDatabase` على قاعدة الاختبار.
- **قابلية التعديل** (القسم 7‑أ من التصميم):
  - لا رقم حساب مكتوب في الكود، فكل حساب عبر `account_mappings`.
  - كل قرار سياسة في موضع واحد، فوقه تعليق برمز القرار.
  - في آخر كل مهمة يُحدَّث «سجل القرارات» إن تغيّر مكان قرار.

## محاور المراجعة

1. ضغط زر الاختصار مرتين، مثل «مرتجع كامل» أو «سند تصحيح»: لا تنشأ مسودتان. تُعاد المسودة القائمة إن وُجدت. الاختبار في المهام 4 و7 و9.
2. سند تصحيح أو مرتجع كامل لمستند صُحِّح من قبل: يُرفض برسالة. الاختبار في المهام 4 و7.
3. إشعار دائن مشتريات بمبلغ أكبر من قيمة البضاعة الباقية في المخزون: الزائد يذهب إلى 5115، والمتوسط لا يصبح سالبًا. الاختبار في المهمة 11.
4. ارتداد شيك سنده مخصَّص على فاتورتين: تعود الفاتورتان مفتوحتين، ولا يمكن إعادة تخصيص السند. الاختبار في المهمة 8.
5. مستند معاكس بتاريخ في فترة مغلقة: يُرفض برسالة الفترة المغلقة، ولا تُكتب أي حركة مخزون. الاختبار في المهمة 6.

---

### المهمة 1: فصل حساب العميل عن حساب المورد (C‑1 / DB‑03)

**الملفات:**
- إنشاء: `Modules/Accounting/Enums/PartySide.php`
- إنشاء: `Modules/Accounting/database/migrations/2026_10_29_000001_split_party_accounts_by_side.php`
- تعديل: `Modules/Accounting/Services/AccountService.php:47-80`، `Modules/Accounting/Services/PartyAccounts.php`، `Modules/Accounting/Entities/Account.php`، `Modules/Core/Entities/User.php:344`
- تعديل: مستعملو `->account` (`BackfillUserAccounts`، `AccountMapping/Index`، `Cheque/Index`، صفحات السندات، `report/general-ledger.blade.php`، `pdf/voucher.blade.php`، `AccountingPdfService`)
- اختبار: `Modules/Accounting/tests/Feature/PartyAccountsTest.php`

**الواجهات:**
- تُنتج: `enum PartySide: int implements HasLabel, HasColor { case RECEIVABLE = 1; case PAYABLE = 2; }`. التسمية والدالة `value()` بأسلوب الـ enums الموجودة. `RECEIVABLE` = «ذمم مدينة»، و`PAYABLE` = «ذمم دائنة».
- تُنتج: `PartySide::parentKey(): string`، وتعيد `PartyAccounts::CUSTOMER_PARENT_KEY` أو `SUPPLIER_PARENT_KEY`.
- تتغير: `AccountService::createForUser(User $user, string $parentKey = 'user_account_parent'): ?Account`. تبحث بـ `user_id` + `party_side` المشتق من المفتاح: المورد = PAYABLE، وما عداه = RECEIVABLE.
- تُنتج: `PartyAccounts::accountIdFor(?int $userId, PartySide $side): int`. `customerAccountId()` و`supplierAccountId()` تصبحان غلافين لها.
- تُنتج: `User::receivableAccount(): HasOne` و`User::payableAccount(): HasOne`. `account()` تبقى اسمًا مستعارًا لـ `receivableAccount()` للتوافق.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_customer_and_supplier_sides_get_separate_accounts`: الطرف نفسه، و`customerAccountId != supplierAccountId`. الأب للأول هو 1130، وللثاني 2110.
  - `test_repeated_calls_return_the_same_account_per_side`.
  - `test_a_restored_trashed_account_keeps_its_side`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL، لأن الطرف نفسه يأخذ الحساب نفسه.
- [ ] **الخطوة 3: التنفيذ.**
  - **الـ migration:**
    - إسقاط الفهرس الفريد `acct_accounts_user_id_unique`.
    - إضافة `party_side` من نوع `unsignedTinyInteger` قابل لـ NULL.
    - تعبئته للحسابات الموجودة من أبيها: تحت 2110 = 2، وإلا 1.
    - فهرس فريد جديد `acct_accounts_user_side_unique` على (`user_id`, `party_side`).
  - **باقي التعديلات:** تعديل الخدمات كما في الواجهات، واستبدال `->account` عند كل مستعمل بالجهة الصحيحة.
- [ ] **الخطوة 4: التشغيل.** `PartyAccountsTest`، ثم كل `Modules/Accounting/tests`. المتوقع: PASS.
- [ ] **الخطوة 5: الإيداع.** `feat(accounting): separate receivable and payable accounts per party`.

### المهمة 2: جهة الحساب في سندات القبض والصرف (C‑5)

**الملفات:**
- إنشاء: `Modules/Accounting/database/migrations/2026_10_29_000002_add_party_side_to_vouchers.php`
- تعديل: `ReceiptVoucher.php` و`PaymentVoucher.php` (cast إلى `PartySide`)، `ReceiptVoucherService::normalizeData/post`، `PaymentVoucherService::normalizeData/post`، صفحات `ReceiptVoucher/{Add,Edit}` و`PaymentVoucher/{Add,Edit}`
- اختبار: `Modules/Accounting/tests/Feature/VoucherPartySideTest.php`

**الواجهات:**
- عمود `party_side` في الجدولين. القيمة الافتراضية: القبض = RECEIVABLE، والصرف = PAYABLE.
- `post()` تستعمل `PartyAccounts::accountIdFor($voucher->client_id, $voucher->party_side)`.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_a_receipt_from_a_supplier_refund_credits_the_payable_account`: سند قبض بجهة PAYABLE وبمبلغ 50. رصيد حساب المورد (تحت 2110) = +50 مدينًا.
  - `test_default_sides_follow_the_voucher_type`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.**
  - العمود والـ cast.
  - حقل Select بالجهة في النموذج من `PartySide::options()`، بعد حقل الطرف، بقيمته الافتراضية.
  - `PaymentAllocationService::openInvoices()` تعرض فواتير المبيعات للجهة المدينة، وفواتير الشراء للجهة الدائنة.
- [ ] **الخطوة 4: التشغيل.** المتوقع: PASS، مع بقاء `SalesSettlementCycleTest` و`PurchaseLedgerCycleTest` ناجحين.
- [ ] **الخطوة 5: الإيداع.** `feat(accounting): vouchers state the party side they settle`.

### المهمة 3: إزالة إلغاء الفواتير المرحَّلة

**الملفات:**
- تعديل:
  - `SalesInvoiceService.php`: حذف `cancel()`، ويبقى `restoreOrderStatus()` لمسار حذف المسودة.
  - `PurchaseInvoiceService.php`: حذف `cancel()` (السطر 202 تقريبًا).
  - `InvoiceStockService.php`: حذف `cancelSalesInvoice()` وما يقابلها في الشراء.
  - `GoodsReceiptService.php`: حذف `cancelForInvoice()` (:222).
  - `DeliveryNoteService.php`: حذف `cancelForInvoice()` (:212).
  - `SalesInvoice/Edit.php` و`PurchaseInvoice/Edit.php`: حذف `cancel()` و`cancellationReason`.
  - `sales-invoice/edit.blade.php` و`purchase-invoice/edit.blade.php`: حذف الزر والبانر الخاص بالإلغاء.
  - `Modules/Accounting/database/seeders/DemoPurchasingSeeder.php:201`.
- تعديل الاختبارات: `SalesSettlementCycleTest`، `InvoiceStockFlowTest`، `PurchaseLedgerCycleTest`، `UnifiedItemsGridTest`.
- إنشاء: `Modules/Accounting/tests/Feature/PostedDocumentsAreFinalTest.php`

**الواجهات:**
- تُنتج: الاختبار `PostedDocumentsAreFinalTest`، وتضيف إليه كل مهمة لاحقة سطرها.

- [ ] **الخطوة 1: كتابة الاختبار الفاشل.**
  - `test_posted_invoices_have_no_cancel_path`:
    - `assertFalse(method_exists(SalesInvoiceService::class, 'cancel'))`، ومثله لـ `PurchaseInvoiceService`.
    - عبر Livewire على فاتورة مرحَّلة: `->call('cancel')` ترمي `MethodNotFoundException`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: الحذف.** احذف الدوال والأزرار والنصوص. اختبارات الإلغاء القائمة:
  - **تُحذف:** الاختبارات التي تفحص الإلغاء نفسه.
  - **تُعاد كتابتها في المهمة 4:** الاختبارات التي تفحص أثر التصحيح، مثل عودة البضاعة وتحرير الأمر، فتُبنى على مرتجع التصحيح.
  - **تُعلَّم:** ضع فوق كل اختبار محذوف سطرًا في وصف الإيداع.
  - **في البذور:** `DemoPurchasingSeeder` يستعمل مرتجعًا كاملًا بدل الإلغاء، بعد المهمة 4. حتى ذلك الحين يُحذف ذلك السيناريو منه.
- [ ] **الخطوة 4: التشغيل.** `PostedDocumentsAreFinalTest` + `Modules/Accounting/tests`. المتوقع: PASS.
- [ ] **الخطوة 5: الإيداع.** `refactor(accounting): posted invoices can no longer be cancelled (R-01)`.

### المهمة 4: سبب المرتجع، ومرتجع التصحيح، و«مرتجع كامل» (C‑4)

**الملفات:**
- إنشاء: `Modules/Accounting/Enums/ReturnReason.php`
- إنشاء: `Modules/Accounting/database/migrations/2026_10_29_000003_add_reason_to_returns.php`
- تعديل:
  - `SalesReturnService.php`: حذف `cancel()`، وإضافة `createFull()`، وفحص التصحيح في `post()`.
  - `PurchaseReturnService.php`: المثل.
  - `SalesReturn.php` و`PurchaseReturn.php`: cast للعمود `reason`.
  - `ManagesReturnDocumentForm.php`: حذف الإلغاء، وإضافة حقل السبب.
  - صفحتا تعديل الفاتورتين: زر «مرتجع كامل» داخل `@can('<Sales|Purchase> Return Create')`.
  - `SalesOrder::activeSalesInvoices()`، و`PurchaseOrder::activePurchaseInvoices()`.
  - `DeliveryNoteService::outstandingLines()`، و`GoodsReceiptService` (الكميات المستلمة).
- اختبار: `Modules/Accounting/tests/Feature/CorrectionReturnTest.php`

**الواجهات:**
- تُنتج: `enum ReturnReason: int implements HasLabel, HasColor { case GOODS_RETURN = 1; case INVOICE_CORRECTION = 2; }`. `GOODS_RETURN` = «إرجاع بضاعة»، و`INVOICE_CORRECTION` = «تصحيح فاتورة».
- تُنتج: `SalesReturnService::createFull(SalesInvoice $invoice, ReturnReason $reason): SalesReturn`، و`PurchaseReturnService::createFull(PurchaseInvoice $invoice, ReturnReason $reason): PurchaseReturn`. كلتاهما تُنشئ مسودة بكل الكميات والقطع القابلة للإرجاع من `returnableLines()`، وتعيد المسودة القائمة إن كانت للفاتورة مسودة.
- تُنتج: `SalesInvoice::isCorrected(): bool` و`PurchaseInvoice::isCorrected(): bool`. تعيدان true إذا وُجد مرتجع مرحَّل بسبب `INVOICE_CORRECTION`.
- **قواعد `post()`:**
  - مرتجع `INVOICE_CORRECTION` يجب أن يساوي **كل** الكمية القابلة للإرجاع، وإلا: `DomainException(__('An invoice correction must return the whole invoice.'))`.
  - بعد الترحيل:
    - الكمية المسلّمة أو المستلمة في الأمر تُنقص بكميات مرتجعات التصحيح المرحَّلة.
    - الأمر يعود إلى «قيد التجهيز» في المبيعات، أو «مستلم» أو «مؤكد» في المشتريات.
    - `active*Invoices()` تستثني الفواتير المصحَّحة، فيمكن إنشاء فاتورة جديدة للأمر.
  - في **التوريد المباشر** يعكس المرتجع الإيراد فقط، دون حركة مخزون (كما هو اليوم).

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_full_correction_return_reopens_the_sales_order_for_a_new_invoice`:
    - أمر بيع لـ 10، ثم فاتورة مرحَّلة، ثم `createFull(INVOICE_CORRECTION)` وترحيله.
    - النتائج: المخزون رجع 10، وحالة الأمر PREPARING.
    - فاتورة جديدة للأمر تنجح وتصرف 10 مرة أخرى.
    - صافي رصيد العميل = قيمة الفاتورة الجديدة فقط.
  - `test_partial_correction_return_is_rejected`.
  - `test_goods_return_does_not_reopen_the_order`.
  - `test_full_correction_of_a_purchase_invoice_lets_the_order_be_invoiced_again`:
    - رصيد 2125 = 0، والمخزون = الكمية المستلمة من الفاتورة الجديدة.
  - `test_full_return_shortcut_returns_the_existing_draft`.
  - `test_posted_returns_have_no_cancel_path`: سطر في `PostedDocumentsAreFinalTest`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.** حسب الواجهات.
  - العمود `reason` من نوع `unsignedTinyInteger` بقيمة افتراضية 1.
  - حقل السبب Select إلزامي في نموذج المرتجع.
  - الترجمة: «مرتجع كامل»، «إرجاع بضاعة»، «تصحيح فاتورة»، ورسالة الرفض: «يجب أن يُرجع مرتجع التصحيح الفاتورة كاملة.»
- [ ] **الخطوة 4: التشغيل.** `CorrectionReturnTest` + `Modules/Accounting/tests`. المتوقع: PASS.
- [ ] **الخطوة 5: الإيداع.** `feat(accounting): invoice correction returns replace invoice cancellation`.

### المهمة 5: مرتجع الاستلام ومرتجع التسليم قبل الفوترة

**الملفات:**
- إنشاء: `Modules/Accounting/database/migrations/2026_10_29_000004_add_reverses_id_to_inventory_documents.php` (الجداول `goods_receipts` و`delivery_notes` و`stock_adjustments` و`stock_transfers`)
- تعديل: `GoodsReceiptService.php` (حذف `cancel()`، وإضافة `createReturn()`، و`post()` للنوع المرتجع، وحساب الكمية المستلمة)، و`DeliveryNoteService.php` بالمثل.
- تعديل: `GoodsReceipt.php`، `DeliveryNote.php`، `Inventory/GoodsReceipt/Form.php` وعرضه، `Inventory/DeliveryNote/Form.php` وعرضه، `Support/InventoryPermissions.php`.
- اختبار: `Modules/Accounting/tests/Feature/InventoryReturnDocumentsTest.php`. وتعديل `InventoryFlowTest::test_cancelling_a_receipt_is_blocked_once_its_stock_was_delivered`، فيصبح اختبارًا لمرتجع الاستلام.

**الواجهات:**
- عمود `reverses_id` قابل لـ NULL، يشير ذاتيًا إلى الجدول نفسه في `goods_receipts` و`delivery_notes`. السند الذي يحمله هو «مرتجع».
- `GoodsReceiptService::createReturn(GoodsReceipt $receipt, array $lines): GoodsReceipt`، حيث `$lines` = `[['goods_receipt_item_id' => int, 'quantity' => float]]`.
  - يرفض إذا كان الأصل غير مرحَّل، أو كان طلب الشراء مُفوترًا: `DomainException(__('This receipt is invoiced; use a purchase return.'))`.
  - **ترحيل المرتجع:**
    - `StockLedgerService::issue` بمتوسط التكلفة الحالي.
    - القيد: مدين 2125 بتكلفة الاستلام الأصلية / دائن المخزون بمتوسط التكلفة، والفرق على 5115.
    - الكمية المستلمة في الطلب تنقص.
- `DeliveryNoteService::createReturn(DeliveryNote $note, array $lines): DeliveryNote`.
  - يرفض إذا كان أمر البيع مُفوترًا: `__('This delivery is invoiced; use a sales return.')`.
  - **ترحيل المرتجع:**
    - `receive` بتكلفة الخروج الأصلية للسطر.
    - القيد: المخزون / تكلفة البضاعة المباعة.
    - الكمية المسلّمة تنقص.
- لا يجوز أن تتجاوز كمية المرتجع (الأصل − مرتجعاته المرحَّلة)، ويُفحص ذلك تحت قفل الأصل.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_receipt_return_after_partial_issue_keeps_average_cost_positive`، وهو سيناريو اختبار الإثبات INV‑01:
    - استلام 10 بسعر 10، واستلام 10 بسعر 30، ثم تسليم 8، ثم مرتجع الاستلام الثاني بـ 10.
    - النتائج: المتوسط ≥ 0، و`ProductCost.value` = رصيد حساب المستودع، و2125 = 0 لذلك الطلب.
  - `test_receipt_return_reduces_received_quantity_on_the_order`.
  - `test_receipt_return_is_rejected_once_the_order_is_invoiced`.
  - `test_delivery_return_restores_stock_at_issue_cost_and_reduces_delivered_quantity`.
  - `test_over_return_is_rejected`.
  - `test_posted_receipts_and_delivery_notes_have_no_cancel_path`: سطر في `PostedDocumentsAreFinalTest`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.**
  - **الواجهة:**
    - زر «مرتجع استلام» أو «مرتجع تسليم» على السند المرحَّل، ويُنشئ مسودة كمياتها مساوية للمتبقي.
    - نموذج المرتجع هو النموذج نفسه بعنوان «مرتجع» ورابط إلى الأصل.
    - الحالة من `status-badge`.
  - **الصلاحيات:** صلاحيات `Goods Receipt Cancel` و`Delivery Note Cancel` تُزال من `InventoryPermissions` (الحذف الفعلي في المهمة 12). إنشاء المرتجع يستعمل صلاحية `Create` للسند نفسه.
- [ ] **الخطوة 4: التشغيل.** `InventoryReturnDocumentsTest` + `InventoryFlowTest`. المتوقع: PASS.
- [ ] **الخطوة 5: الإيداع.** `feat(inventory): receipt and delivery returns replace cancellation`.

### المهمة 6: التسوية المعاكسة والتحويل المعاكس

**الملفات:**
- تعديل: `StockAdjustmentService.php` (حذف `cancel()` من السطر 186، وإضافة `createCounter()`)، `StockTransferService.php` (حذف `cancel()` من السطر 163، وإضافة `createCounter()`)، `Inventory/StockAdjustment/Form.php`، `Inventory/StockTransfer/Form.php` وعرضاهما
- اختبار: `Modules/Accounting/tests/Feature/CounterInventoryDocumentsTest.php`

**الواجهات:**
- `StockAdjustmentService::createCounter(StockAdjustment $adjustment): StockAdjustment`. مسودة بالكميات المعكوسة، ومرتبطة عبر `reverses_id`. هذا العمود تضيفه migration المهمة 5 إلى `stock_adjustments` و`stock_transfers` أيضًا. سطور الزيادة فيها بلا `unit_cost`، فتأخذ متوسط التكلفة لحظة الترحيل.
- `StockTransferService::createCounter(StockTransfer $transfer): StockTransfer`. المستودعان معكوسان والكميات نفسها.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_counter_adjustment_restores_quantity_without_negative_cost`.
  - `test_counter_transfer_moves_stock_back`.
  - `test_counter_document_in_a_closed_period_is_rejected_and_writes_no_movement`:
    - إغلاق الشهر، ثم ترحيل بتاريخ فيه.
    - النتائج: `DomainException`، و`StockMovement::count()` لم يتغير.
  - `test_posted_adjustments_and_transfers_have_no_cancel_path`: سطر في `PostedDocumentsAreFinalTest`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.**
  - ترحيل المستند المعاكس يمر بـ `post()` العادية.
  - **فحص الفترة قبل المخزون:** إذا كانت الفترة مغلقة يجب أن يفشل قبل كتابة أي حركة. انقل استدعاء التحقق من الفترة (`JournalEntryService` أو helper) إلى بداية `post()` في كل مستندات المخزون.
- [ ] **الخطوة 4: التشغيل.** المتوقع: PASS.
- [ ] **الخطوة 5: الإيداع.** `feat(inventory): counter adjustment and counter transfer replace cancellation`.

### المهمة 7: سند التصحيح بدل إلغاء السندات

**الملفات:**
- إنشاء: `Modules/Accounting/database/migrations/2026_10_29_000005_add_correction_link_to_vouchers.php`
- تعديل: `ReceiptVoucherService.php` (حذف `cancel()` من السطر 134، وإضافة `createCorrection()`)، `PaymentVoucherService.php` بالمثل، `ReceiptVoucher.php`، `PaymentVoucher.php`، `PaymentAllocationService::unallocatedAmount()` (:173)، صفحتا Edit وعرضاهما
- اختبار: `Modules/Accounting/tests/Feature/CorrectionVoucherTest.php`

**الواجهات:**
- أعمدة `corrects_type` و`corrects_id` (morph قابل لـ NULL) و`corrected_at` في الجدولين.
- `ReceiptVoucherService::createCorrection(ReceiptVoucher $voucher): PaymentVoucher`، و`PaymentVoucherService::createCorrection(PaymentVoucher $voucher): ReceiptVoucher`.
  - مسودة بالطرف والجهة والحساب والعملة والسعر والمبلغ نفسها، وبرابط `corrects`.
  - تُرفض إذا كان الأصل غير مرحَّل أو `corrected_at` غير فارغ، أو كان للأصل شيك قائم: `__('Handle the cheque from the cheques register.')`.
  - تعيد مسودة التصحيح القائمة إن وُجدت.
- **عند ترحيل سند يحمل `corrects`:** داخل قفل الأصل، نفّذ `deallocateAll($original)` ثم اضبط `corrected_at`.
- `unallocatedAmount()` تعيد 0 لكل سند `corrected_at` فيه غير فارغ، أو يحمل `corrects`.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_correcting_a_receipt_reopens_its_invoices_and_nets_the_customer_to_zero`:
    - قبض 100 مخصَّص على فاتورة 100، ثم ترحيل سند التصحيح.
    - النتائج: متبقي الفاتورة 100، ورصيد العميل = 100 (الفاتورة فقط)، ويبقى الأصل POSTED.
  - `test_a_corrected_voucher_cannot_be_allocated_or_corrected_again`.
  - `test_correction_uses_the_original_exchange_rate`: عملة أجنبية، و`exchange_rate` للتصحيح = سعر الأصل، ولا قيد فرق صرف.
  - `test_posted_vouchers_have_no_cancel_path`: سطر في `PostedDocumentsAreFinalTest`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.**
  - **الواجهة:** زر «سند تصحيح» داخل `@can('Payment Voucher Create')` على سند القبض، والعكس على سند الصرف. وبانر «صُحِّح بالسند …» يربط بين السندين.
  - **الترجمة:** «سند تصحيح»، «صُحِّح بالسند»، «يصحّح السند».
- [ ] **الخطوة 4: التشغيل.** `CorrectionVoucherTest` + `SalesSettlementCycleTest`. الاختبارات التي كانت تختبر إلغاء السند تُعاد كتابتها على سند التصحيح.
- [ ] **الخطوة 5: الإيداع.** `feat(accounting): correction vouchers replace voucher cancellation`.

### المهمة 8: ارتداد الشيك حدث له قيده (GL‑02، GL‑05)

**الملفات:**
- تعديل: `Modules/Accounting/Services/ChequeService.php:78-125`، `Modules/Accounting/Livewire/Pages/Cheque/Index.php:105-157`
- اختبار: `Modules/Accounting/tests/Feature/ChequeBounceTest.php`

**الواجهات:**
- تتغير: `ChequeService::markBounced(Cheque $cheque, ?string $date = null, ?string $reason = null): Cheque`، و`ChequeService::cancel(Cheque $cheque, ?string $date = null, ?string $reason = null): Cheque`. التاريخ الافتراضي اليوم.
- **السلوك:**
  - قفل الشيك ثم **إعادة فحص** `UNDER_COLLECTION` بعد القفل.
  - قيد جديد مرحَّل، `source_type = 'ChequeBounce'`، و`sourceable` = الشيك، بسعر صرف السند (C‑6):
    - **الشيك الوارد:** مدين حساب الطرف (حسب جهة السند) / دائن `voucher.account_id`.
    - **الشيك الصادر:** معكوس.
  - `deallocateAll(voucher)`.
  - `voucher.corrected_at = now()`، فيصبح المبلغ القابل للتخصيص صفرًا. **قيد السند الأصلي لا يُمس**، والسند يبقى POSTED.
  - حالة الشيك BOUNCED أو CANCELLED، و`journal_entry_id` = قيد الارتداد.
- **ما يُزال من `ChequeService`:** استدعاء `void()` واستدعاء `cancel()` الخاص بالسندات.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_bounce_posts_its_own_entry_and_keeps_the_voucher_posted`.
  - `test_bounce_reopens_both_allocated_invoices_and_blocks_reallocation`.
  - `test_bounce_is_dated_on_the_bounce_date`.
  - `test_clear_then_bounce_on_a_stale_model_is_rejected`: نسختان من الشيك، `markCleared($a)` ثم `markBounced($b)`. النتائج: `DomainException`، وقيد صرف واحد فقط.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.**
  - حسب الواجهات.
  - إجراءا Filament في `Cheque/Index` يطلبان حقلين في النافذة: التاريخ (افتراضيه اليوم) والسبب.
  - مع `->modalHeading(__('Bounce cheque'))`، و`->modalDescription(...)` تشرح أن قيدًا جديدًا سيُرحَّل (UI‑08).
- [ ] **الخطوة 4: التشغيل.** `ChequeBounceTest` + `SalesSettlementCycleTest`. المتوقع: PASS.
- [ ] **الخطوة 5: الإيداع.** `feat(accounting): cheque bounce posts its own entry instead of cancelling the voucher`.

### المهمة 9: القيد العكسي اليدوي، وإزالة الإبطال وإلغاء قيد الإقفال (GL‑01، GL‑13)

**الملفات:**
- تعديل:
  - `JournalEntryService.php`: إضافة `draftReversal()`، وجعل `void()` للاستعمال الداخلي في فك التخصيص فقط.
  - `JournalEntry.php`: `canBeVoided()` تصبح `canBeReversed()`.
  - `JournalEntry/Index.php:90-125`: حذف Void، وإضافة «إنشاء قيد عكسي»، وقصر Post/Edit/Delete على `isManual()`.
  - `PeriodClosingVoucherService.php`: حذف `cancel()` من السطر 106.
  - `PeriodClosingVoucher/Index.php:58`.
  - `AccountingReportService.php:349-351`.
- اختبار: `Modules/Accounting/tests/Feature/JournalReversalTest.php`. وتعديل `LedgerReportsTest::test_closing_the_year_moves_profit_to_retained_earnings`، فيصبح: إعادة فتح، ثم قيد تعديل، ثم إقفال جديد بالفرق.

**الواجهات:**
- `JournalEntryService::draftReversal(JournalEntry $entry, ?string $date = null): JournalEntry`.
  - للقيد اليدوي المرحَّل فقط، الذي ليس عكسيًا وليس له عكسي (ولا حتى مسودة).
  - تُنشئ مسودة بسطور معكوسة، مع `reversal_of_entry_id` و`entry_date = $date ?? today`.
  - يبقى الأصل POSTED.
- `JournalEntry::canBeReversed(): bool`.
- **التقرير:** `sums(... excludeClosing)` يستبعد أيضًا كل قيد `reversal_of_entry_id` فيه يشير إلى قيد `source_type = PeriodClosingVoucher` (إصلاح دفاعي).

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_draft_reversal_swaps_lines_and_leaves_the_original_posted`.
  - `test_a_second_reversal_is_rejected_even_while_the_first_is_draft`.
  - `test_document_entries_cannot_be_reversed_manually`.
  - `test_reclosing_after_adjustment_posts_only_the_difference`:
    - إقفال 2025 بربح 600، ثم إعادة فتح، ثم قيد إيراد 100 بتاريخ 2025، ثم إقفال.
    - النتائج: قيد إقفال ثانٍ بـ 100، وقائمة دخل 2025 = 700، والميزانية متوازنة.
  - `test_posted_journal_entries_and_closing_vouchers_have_no_cancel_path`: سطر في `PostedDocumentsAreFinalTest`، و`JournalEntryService::void` لا يستدعيها إلا `PaymentAllocationService` (يُفحص بـ grep داخل الاختبار على `Modules/`).
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.**
  - حسب الواجهات.
  - زر الإجراء بأيقونة ونص «إنشاء قيد عكسي»، مع `modalHeading`، ويحوّل إلى صفحة المسودة الجديدة.
- [ ] **الخطوة 4: التشغيل.** `JournalReversalTest` + `LedgerReportsTest` + `JournalEntryPostingTest`. المتوقع: PASS.
- [ ] **الخطوة 5: الإيداع.** `feat(ledger): manual reversal drafts replace voiding; closing vouchers are final`.

### المهمة 10: حركة إعادة التقييم وحارس القيمة السالبة في سجل المخزون

**الملفات:**
- تعديل: `Modules/Accounting/Enums/StockMovementType.php` (إضافة `REVALUATION`)، `Modules/Accounting/Services/StockLedgerService.php`
- اختبار: `Modules/Accounting/tests/Unit/StockRevaluationTest.php`

**الواجهات:**
- `StockLedgerService::revalue(array $data): float`، حيث `$data` = `{product_id, warehouse_id, amount (±), date, source, source_line_id?, reference_no?, description?}`.
  - تقفل `product_costs`، وتضيف المبلغ إلى `value` بحد أدنى صفر، وتعيد حساب المتوسط دون تغيير الكمية.
  - تسجّل حركة كميتها 0، و`total_cost` = المبلغ المطبَّق.
  - **تعيد المبلغ المطبَّق فعلًا.** إن كانت الكمية صفرًا تعيد 0 دون حركة.
- **حارس الأمان:** في `receive` و`issue` و`issueExact`، لا تُحفظ `value` ولا `average_cost` بقيمة سالبة. ترمي `DomainException(__('Stock value cannot become negative.'))`.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_revalue_changes_average_cost_but_not_quantity`: 10 بسعر 10، ثم +20، فيصبح المتوسط 12.
  - `test_revalue_is_capped_at_zero_value_and_returns_the_applied_amount`: 10 بسعر 10، ثم −150، فيُطبَّق −100 والمتوسط 0.
  - `test_revalue_with_no_stock_applies_nothing`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.** حسب الواجهات، مع ترتيب القفل: `product_costs` أولًا ثم `stock_balances` (INV‑08).
- [ ] **الخطوة 4: التشغيل.** المتوقع: PASS، مع بقاء `InventoryFlowTest` ناجحًا.
- [ ] **الخطوة 5: الإيداع.** `feat(inventory): value-only revaluation movement and negative value guard`.

### المهمة 11: الإشعارات الدائنة والمدينة (C‑8): الكيانات والخدمات

**الملفات:**
- إنشاء: `Modules/Accounting/Enums/NoteDirection.php`
- إنشاء: `Modules/Accounting/database/migrations/2026_10_29_000006_create_accounting_adjustment_notes_tables.php` (أربعة جداول: `sales_adjustment_notes`، `sales_adjustment_note_items`، `purchase_adjustment_notes`، `purchase_adjustment_note_items`)
- إنشاء: `Modules/Accounting/Entities/{SalesAdjustmentNote,SalesAdjustmentNoteItem,PurchaseAdjustmentNote,PurchaseAdjustmentNoteItem}.php`
- إنشاء: `Modules/Accounting/Services/{SalesAdjustmentNoteService,PurchaseAdjustmentNoteService}.php`
- تعديل: `Modules/Accounting/Services/PaymentAllocationService.php`، بإضافة الإشعار الدائن مصدرًا في `INVOICE_FOR_SOURCE`، وإدخال الإشعارات المرحَّلة في `outstanding()`.
- اختبار: `Modules/Accounting/tests/Feature/AdjustmentNotesTest.php`

**الواجهات:**
- `enum NoteDirection: int implements HasLabel, HasColor { case CREDIT = 1; case DEBIT = 2; }`. `CREDIT` = «إشعار دائن»، و`DEBIT` = «إشعار مدين».
- **رأس الإشعار:** `reference_no` من `ReferenceNumberService` (`SAN` للمبيعات، و`PAN` للمشتريات)، `<sales|purchase>_invoice_id`، `client_id`، `direction`، `reason` (نص إلزامي)، `note_date`، `currency_id`، `exchange_rate` (من الفاتورة)، `tax_rate` (من الفاتورة)، `sub_total`، `tax_amount`، `total_amount`، `status` (enum جديد `Enums/AdjustmentNoteStatus.php`: `DRAFT = 1`، `POSTED = 2`، بأسلوب `HasLabel` و`HasColor`)، `journal_entry_id`، `allocated_amount`، `posted_by/at`، `created_by`، softDeletes.
- **السطور:** `invoice_item_id` و`amount`، والمبلغ صافٍ موجب.
- **الخدمتان:** `create(array $data)`، `update()`، `delete()` (للمسودة فقط)، `post()` (قفل + إعادة فحص). لا `cancel()`.
- **قيد الإشعار الدائن للمبيعات:**
  - مدين حساب الإيراد لكل سطر بحسب نوعه (بضاعة 4100، خدمة 4110، نقل 4150)، كما في `SalesLedgerAccounts`.
  - مدين الضريبة 2140.
  - دائن حساب العميل بجهة RECEIVABLE.
  - **الإشعار المدين:** القيد معكوس.
  - **الإشعار الدائن يُخصَّص تلقائيًا** على فاتورته حتى حدّ المتبقي، والإشعار المدين يزيد `outstanding()`.
- **قيد الإشعار الدائن للمشتريات:**
  - مدين حساب المورد بجهة PAYABLE / دائن ضريبة المشتريات 1180.
  - **وتوزيع الصافي لكل سطر:**
    - `$onHand = min(كمية السطر في الفاتورة بوحدة المخزون، الرصيد الحالي للصنف)`.
    - `$share = round(amount × onHand / qty, 4)`.
    - `$applied = StockLedgerService::revalue(-$share × rate)`، ثم دائن المخزون بقيمة `$applied`.
    - الباقي (`amount×rate − applied`) دائن 5115.
    - في التوريد المباشر يذهب كله إلى 5100.
  - **الإشعار المدين للمشتريات:** القيد معكوس.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_sales_credit_note_reduces_revenue_tax_and_customer_and_settles_the_invoice`:
    - فاتورة 100 + ضريبة 16%، ثم إشعار دائن بـ 10.
    - النتائج: الإيراد −10، والضريبة −1.6، والعميل −11.6، والمتبقي 104.4.
  - `test_sales_debit_note_increases_outstanding`.
  - `test_purchase_credit_note_revalues_stock_on_hand_and_sends_the_sold_part_to_price_variance`:
    - شراء 10 بسعر 10، ثم بيع 4، ثم إشعار دائن بـ 20.
    - النتائج: المخزون −12 (لـ 6 قطع)، والمتوسط 8، و5115 −8، والمورد −20.
  - `test_purchase_credit_note_larger_than_stock_value_never_makes_cost_negative`: الزائد على 5115.
  - `test_note_uses_the_invoice_exchange_rate`.
  - `test_notes_require_a_posted_invoice_and_a_reason`.
  - `test_posted_notes_have_no_cancel_path`: سطر في `PostedDocumentsAreFinalTest`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.**
  - حسب الواجهات.
  - يُعاد استعمال حساب الحسابات من `SalesLedgerAccounts` و`LedgerAccounts`، فلا تُنسخ خرائط الحسابات.
  - إضافة رموز التسلسل `sales_adjustment_note` و`purchase_adjustment_note` إلى `ReferenceNumberService` إن لزم تسجيلها.
- [ ] **الخطوة 4: التشغيل.** `AdjustmentNotesTest` + `SalesSettlementCycleTest` + `PurchaseLedgerCycleTest`. المتوقع: PASS.
- [ ] **الخطوة 5: الإيداع.** `feat(accounting): sales and purchase credit/debit notes`.

### المهمة 12: صفحات الإشعارات والصلاحيات وتنظيف صلاحيات الإلغاء

**الملفات:**
- إنشاء: `Modules/Accounting/Livewire/Pages/AdjustmentNote/{SalesIndex,SalesForm,PurchaseIndex,PurchaseForm}.php`، والعروض المرآة في `resources/views/livewire/pages/adjustment-note/`. النموذج واحد للإضافة والتعديل، على شكل `Inventory/*/Form`، مع `document-header` و`status-badge`.
- تعديل:
  - `Modules/Accounting/routes/web.php`: المسارات `accounting.sales-adjustment-notes.{index,add,edit}` و`accounting.purchase-adjustment-notes.{...}`.
  - `Providers/SidebarServiceProvider.php`.
  - `Support/LedgerPermissions.php`.
  - `Support/InventoryPermissions.php`.
  - `Modules/Core/Livewire/Pages/Roles/Index.php`.
  - `Modules/Accounting/Lang/ar.json`.
  - صفحتا تعديل الفاتورتين: زر «إشعار دائن/مدين» داخل `@can`.
- إنشاء: `Modules/Accounting/database/migrations/2026_10_29_000007_sync_ledger_permissions_after_r01.php`
- اختبار: `Modules/Accounting/tests/Feature/AdjustmentNotePagesTest.php`

**الواجهات:**
- **صلاحيات جديدة:**
  - `Sales Adjustment Note View List/Create/Update/Delete/Post` في المجموعة `Sales Adjustment Note Management`.
  - ومثلها لـ `Purchase Adjustment Note`.
- **صلاحيات تُحذف:**
  - `Sales Invoice Cancel`، `Purchase Invoice Cancel`، `Sales Return Cancel`، `Purchase Return Cancel`، `Receipt Voucher Cancel`، `Payment Voucher Cancel`، `Period Closing Voucher Cancel`، `Goods Receipt Cancel`، `Delivery Note Cancel`، `Stock Transfer Cancel`، `Stock Adjustment Cancel`، `Journal Entry Void`.
  - الـ migration تستدعي `LedgerPermissions::sync()` و`InventoryPermissions::sync()`، ثم `Permission::whereIn('name', [...])->delete()` مع مسح ذاكرة الصلاحيات.
- **صلاحيات تبقى:** `Cheque Bounce` و`Cheque Cancel` (حدثان)، و`Fiscal Year Reopen`، و`Accounting Period Reopen`.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_index_and_form_require_view_list_and_create`: الحالتان 403 و200.
  - `test_post_action_requires_post_permission`.
  - `test_cancel_permissions_no_longer_exist`.
  - `test_sidebar_item_uses_the_route_permission`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.**
  - حسب مهارتَي livewire-views وpermissions.
  - الترجمات بالفصحى: «الإشعارات الدائنة والمدينة للمبيعات»، «إشعار دائن»، «إشعار مدين»، «سبب الإشعار»، وأسماء الصلاحيات.
  - إزالة صلاحيات الإلغاء من خرائط `Roles/Index.php` إن لم تعد مستعملة.
- [ ] **الخطوة 4: التشغيل.** `AdjustmentNotePagesTest` + `php -r` للتحقق من صحة JSON. المتوقع: PASS، و`No error`.
- [ ] **الخطوة 5: الإيداع.** `feat(accounting): adjustment note pages; remove cancel/void permissions`.

### المهمة 13: إغلاق الموجة

**الملفات:**
- تعديل: `Modules/Accounting/ACCOUNTING_GUIDE_AR.md` (الأسطر 541 و763 و906 و1013 التي تصف الإبطال والإلغاء)، `Modules/Accounting/README_AR.md` إن ذكر الإلغاء، `.claude/skills/global-components/SKILL.md`
- تعديل: `docs/audit/2026-10-28-comprehensive-audit.md`

- [ ] **الخطوة 1: فحص الكود.**
  ```
  grep -rnE "function (cancel|void)\(" Modules/Accounting/Services
  ```
  المتوقع: لا يظهر إلا `PurchaseOrderService::cancel`، و`SalesOrderService::cancel`، و`SalesQuotationService::cancel`، و`PurchaseQuotationService::cancel`، و`ProductionOrderService::cancel`، و`ChequeService::cancel` (حدث)، و`JournalEntryService::void` (داخلي).
- [ ] **الخطوة 2: الاختبارات.** `vendor/bin/phpunit -c phpunit.mysql.xml --no-coverage`. المتوقع: OK.
- [ ] **الخطوة 3: التوثيق.**
  - تحديث الدليل العربي بقاعدة R‑01 والمستندات المعاكسة.
  - إضافة إلى `global-components` §5 سطر: «المستند المرحَّل نهائي؛ التصحيح بالمستندات المعاكسة: createFull/createReturn/createCounter/createCorrection/draftReversal، والإشعارات».
  - تعليم النتائج المغلقة في التقرير: INV‑01، GL‑01، GL‑02، GL‑05، SAL‑01، SAL‑07، PUR‑10، INV‑06، DB‑03.
- [ ] **الخطوة 4: الإيداع.** `docs: posted documents are final (R-01)`.

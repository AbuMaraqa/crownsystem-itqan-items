# الموجتان 0 و1: التثبيت والإصلاحات الحرجة — خطة التنفيذ

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**الهدف:** هدفان:
1. إيداع عمل الجلسة الأخرى على فرع نظيف، مع مجموعة اختبارات خضراء.
2. سدّ الثغرتين الحرجتين: تصعيد الصلاحيات عبر الأدوار (SEC‑01)، وتغيير العملة الأساسية دون حماية (DB‑01).

**المعمارية:**
- القاعدة في Service أو Support، والصفحة تستدعيها فقط.
- قاعدة الأدوار القابلة للإسناد في Core، لأنها تخص المستخدمين.
- حارس العملة الأساسية في `CurrencyExchangeRateService` في وحدة المحاسبة، وتستدعيه صفحة إعدادات Core.

**التقنيات:** Laravel 12، nwidart modules، Livewire 3، Filament 3 (forms)، Spatie Permission، PHPUnit على MySQL.

**التصميم:** [docs/superpowers/specs/2026-10-28-accounting-audit-remediation-design.md](../specs/2026-10-28-accounting-audit-remediation-design.md)، والتدقيق: [docs/audit/2026-10-28-comprehensive-audit.md](../../audit/2026-10-28-comprehensive-audit.md).

## القيود العامة

- اتباع مهارات المشروع `.claude/skills/{laravel,livewire-views,permissions,global-components,translations}`. حمّل المهارة المناسبة قبل كتابة الكود.
- رسائل الأخطاء: `throw new DomainException(__('English source'))`، ويضاف المقابل العربي بالفصحى إلى `Modules/<Module>/Lang/ar.json`.
- التحقق من الصلاحية: `$this->authorize('<Entity> <Action>')` في أول كل دالة Livewire تغيّر البيانات. لا يُستخدم `hasRole`.
- الاختبارات: `vendor/bin/phpunit -c phpunit.mysql.xml --no-coverage <path> [--filter <name>]`.
- إذا كانت جلسة أخرى تعمل على `crownsystem_test` (افحص `SHOW PROCESSLIST`)، فاستخدم نسخة مؤقتة من الإعداد تشير إلى قاعدة منفصلة.
- لا يُشغَّل `php artisan migrate` على قاعدة التطوير دون إذن المالك.
- `php -l` على كل ملف PHP معدّل.
- **قابلية التعديل** (القسم 7‑أ من التصميم): كل قاعدة في موضع واحد باسمه، ولا تُنسخ.
- الإيداع يحتاج موافقة المالك. اسأله مرة واحدة في بداية التنفيذ عن الإيداع على الفرع `audit-remediation`.

## محاور المراجعة

1. مستخدم يملك «User Update» ويعدّل **نفسه**: يجب ألا يستطيع إضافة أي دور يمنح صلاحية لا يملكها. الاختبار في المهمة 3.
2. طلب Livewire معدَّل يرسل `roles` بمعرّف دور مخفي من القائمة: يجب أن يرفضه الخادم لا الواجهة فقط. الاختبار في المهمة 3.
3. حفظ الإعدادات دون تغيير العملة الأساسية بعد وجود قيود: يجب أن ينجح. الحارس يمنع **التغيير** فقط، لا الحفظ. الاختبار في المهمة 4.
4. مسودة قيد فقط، دون أي قيد مرحَّل: تغيير العملة ممنوع. السبب أن المسودات تحمل أسعار صرف محسوبة على العملة القديمة. الاختبار في المهمة 4.
5. غياب الإعداد وغياب عملة افتراضية: `baseCurrencyId()` يعيد `null` بدل أول عملة، فيُرفض الترحيل برسالة واضحة. الاختبار في المهمة 4.

---

### المهمة 1: تثبيت عمل الجلسة الأخرى على فرع

**الملفات:**
- تعديل: `Modules/Accounting/tests/Feature/SalesSettlementCycleTest.php` (قرابة السطر 144)
- إيداع كل الملفات المعدّلة وغير المتتبعة الحالية، ما عدا `storage/framework/lsp-*.php`

- [ ] **الخطوة 1: إنشاء الفرع.** بعد موافقة المالك: `git switch -c audit-remediation`.
- [ ] **الخطوة 2: تصحيح توقّع الاختبار.** الفاتورة بلا أمر بيع تُنشئ قيدين صحيحين: الإيراد (`source_module = 'Accounting'`) وتكلفة البضاعة المباعة (`source_module = 'Inventory'`). عدّل التأكيد في `test_a_stale_draft_cannot_post_the_invoice_twice` إلى:
  ```php
  $this->assertSame(1, JournalEntry::query()->where('source_type', 'SalesInvoice')->where('source_module', 'Accounting')->count());
  $this->assertSame(1, JournalEntry::query()->where('source_type', 'SalesInvoice')->where('source_module', 'Inventory')->count());
  ```
- [ ] **الخطوة 3: التشغيل.** `vendor/bin/phpunit -c phpunit.mysql.xml --no-coverage Modules/Accounting/tests/Feature/SalesSettlementCycleTest.php`. المتوقع: OK.
- [ ] **الخطوة 4: الإيداع.** `git add Modules docs` ثم `git commit -m "fix: settle sales invoices, voucher cancel, audit docs (other session) + correct stale-draft test"`.

### المهمة 2: إيقاف البث في الاختبارات

**الملفات:**
- تعديل: `phpunit.mysql.xml`
- تعديل: `Modules/Core/tests/Feature/LiveNotificationsTest.php`

- [ ] **الخطوة 1: إيقاف البث.** أضف `<env name="BROADCAST_CONNECTION" value="log" force="true"/>` في `phpunit.mysql.xml`.
- [ ] **الخطوة 2: تشغيل اختبار الإشعارات.** المتوقع: FAIL بالحالة 403. السبب أن قنوات `routes/channels.php` سُجّلت على مشغّل `log` لحظة الإقلاع.
- [ ] **الخطوة 3: إعادة تسجيل القنوات في الاختبار.** في `LiveNotificationsTest::test_user_can_only_subscribe_to_own_private_notification_channel`، بعد `config(['broadcasting.default' => 'reverb'])`: نفّذ `Broadcast::forgetDrivers()` ثم `require base_path('routes/channels.php')`، حتى تُسجَّل القنوات على مشغّل reverb.
- [ ] **الخطوة 4: التحقق.** شغّل المجموعة كاملة. المتوقع: لا فشل، ولا سطور `Pusher error` جديدة في `storage/logs/laravel.log` (قارن عددها قبل التشغيل وبعده بـ `grep -c "Pusher error"`).
- [ ] **الخطوة 5: الإيداع.** `test: stop broadcasting to a live Reverb server during tests`.

### المهمة 3: منع تصعيد الصلاحيات عبر الأدوار (SEC‑01)

**الملفات:**
- إنشاء: `Modules/Core/Support/AssignableRoles.php`
- تعديل: `Modules/Core/Livewire/Concerns/UserFormSchema.php:97`
- تعديل: `Modules/Core/Livewire/Pages/Users/Add.php:46`، `Modules/Core/Livewire/Pages/Users/Edit.php:50-60`
- تعديل: `Modules/Core/Lang/ar.json`
- اختبار: `Modules/Core/tests/Feature/AssignableRolesTest.php`

**الواجهات:**
- تُنتج: `AssignableRoles::for(User $actor): Collection<int, Role>`. تعيد الأدوار التي تقع كل صلاحياتها ضمن صلاحيات المنفِّذ، ودور `UserRole::SUPER_ADMIN->value` فقط إن كان المنفِّذ يملكه.
- تُنتج: `AssignableRoles::assert(User $actor, array $roleIds): void`. ترمي `DomainException(__('You cannot assign a role with permissions you do not have.'))` إن وُجد معرّف خارج القائمة.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_a_user_manager_cannot_see_or_assign_super_admin`:
    - منفِّذ له صلاحيتا `User View List` و`User Update` فقط.
    - `AssignableRoles::for($actor)` لا تحتوي `Super Admin`.
    - `assert($actor, [$superAdmin->id])` ترمي `DomainException`.
  - `test_roles_within_the_actors_permissions_are_assignable`:
    - دور «Clerk» له `User View List` فقط.
    - يكون متاحًا لنفس المنفِّذ، ولا تُرمى أي استثناء.
  - `test_editing_self_cannot_escalate`:
    - `Livewire::actingAs($actor)->test(Users\Edit::class, ['user' => $actor])->set('data.roles', [$superAdmin->id])->call('save')`.
    - بعدها: `$actor->refresh()->hasRole('Super Admin') === false`.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL، لأن الصنف `AssignableRoles` غير موجود.
- [ ] **الخطوة 3: التنفيذ.**
  - `AssignableRoles` كما في الواجهات. المقارنة على أسماء الصلاحيات: `$role->permissions->pluck('name')->diff($actor->getAllPermissions()->pluck('name'))->isEmpty()`.
  - `UserFormSchema` يستعمل `AssignableRoles::for(auth()->user())->pluck('name', 'id')`.
  - `Add::save()` و`Edit::save()` يستدعيان `AssignableRoles::assert(...)` قبل `syncRoles`.
  - **عند التعديل:** الأدوار التي يملكها المستخدم الهدف وخارج قدرة المنفِّذ تبقى كما هي ولا تُزال. أي أن المزامنة تتم على الأدوار المتاحة للمنفِّذ فقط.
  - الرسالة تُضاف إلى `Modules/Core/Lang/ar.json`: «لا يمكنك إسناد دور يتضمن صلاحيات لا تملكها.»
- [ ] **الخطوة 4: التشغيل.** المتوقع: PASS، مع بقاء `Modules/Core/tests/Feature/UserAndSupplierFormsTest.php` ناجحًا.
- [ ] **الخطوة 5: الإيداع.** `fix(core): users can only assign roles within their own permissions`.

### المهمة 4: حماية العملة الأساسية (DB‑01)

**الملفات:**
- تعديل: `Modules/Accounting/Services/CurrencyExchangeRateService.php:11-18`
- تعديل: `Modules/Core/Livewire/Pages/Settings/Index.php:89-99,197`
- تعديل: `Modules/Accounting/Console/Commands/SwitchBaseCurrency.php:48-57`
- تعديل: `Modules/Accounting/Livewire/Pages/JournalEntry/Add.php:32` (العملة الافتراضية من `baseCurrencyId()` لا من `is_default`، وهو GL‑09 جزئيًا)
- تعديل: `Modules/Accounting/Lang/ar.json`
- اختبار: `Modules/Accounting/tests/Feature/BaseCurrencyGuardTest.php`

**الواجهات:**
- تُنتج: `CurrencyExchangeRateService::baseCurrencyIsLocked(): bool`. تعيد true إذا وُجد أي `JournalEntry` بأي حالة، بما في ذلك المحذوف ناعمًا.
- تُنتج: `CurrencyExchangeRateService::assertBaseCurrencyCanChange(?int $newCurrencyId): void`. لا تفعل شيئًا إذا كانت العملة الجديدة تساوي الحالية. وإلا ترمي `DomainException(__('The base currency cannot change after journal entries exist.'))` إذا كانت العملة مقفلة.
- تتغير: `baseCurrencyId(): ?int`. تُحذف منها خطوة البديل الأخيرة `Currency::query()->value('id')`، ويبقى الإعداد ثم `is_default`.

- [ ] **الخطوة 1: كتابة الاختبارات الفاشلة.**
  - `test_base_currency_cannot_change_once_an_entry_exists`: مع مسودة قيد واحدة، `assertBaseCurrencyCanChange($otherId)` ترمي استثناء.
  - `test_saving_settings_with_the_same_base_currency_is_allowed`: لا يُرمى أي استثناء عند `$sameId`.
  - `test_settings_page_rejects_a_new_base_currency_after_entries`: عبر Livewire، تبقى `GeneralSettings::base_currency_id` على قيمتها، ويظهر toast خطأ.
  - `test_base_currency_does_not_fall_back_to_the_first_currency`:
    - لا إعداد ولا `is_default`، و`baseCurrencyId()` يعيد `null`.
    - `postInventoryJournal` يرمي `Base currency is not configured.`
  - `test_switch_command_refuses_when_any_entry_exists`: `artisan('accounting:switch-base-currency', [...])->assertFailed()` مع مسودة قيد.
- [ ] **الخطوة 2: التشغيل.** المتوقع: FAIL.
- [ ] **الخطوة 3: التنفيذ.**
  - **صفحة الإعدادات:**
    - الحقل `base_currency_id` يصبح `->disabled(fn () => app(CurrencyExchangeRateService::class)->baseCurrencyIsLocked())`، مع `->helperText(__('Locked because journal entries exist.'))`.
    - في `save()` استدعِ `assertBaseCurrencyCanChange($data['base_currency_id'])` داخل try/catch، والخطأ يظهر بـ `Toaster::error`.
  - **الأمر:** يرفض التنفيذ إذا كان `baseCurrencyIsLocked()` صحيحًا، حتى مع `--force`.
  - **ملف الترجمة:** أضف «لا يمكن تغيير العملة الأساسية بعد وجود قيود يومية.» و«مقفلة لوجود قيود يومية.»
- [ ] **الخطوة 4: التشغيل.** المتوقع: PASS، مع بقاء `JournalEntryPostingTest` و`LedgerReportsTest` ناجحين.
- [ ] **الخطوة 5: الإيداع.** `fix(accounting): lock the base currency once journal entries exist`.

### المهمة 5: التحقق الكامل للموجتين

- [ ] **الخطوة 1:** `vendor/bin/phpunit -c phpunit.mysql.xml --no-coverage`. المتوقع: OK، مع 5 اختبارات تخطّاها PHPUnit فقط (Jetstream).
- [ ] **الخطوة 2: تحديث التقرير.** أضف في `docs/audit/2026-10-28-comprehensive-audit.md` سطرًا تحت جدولَي SEC‑01 وDB‑01: «أُصلح في الموجة 1» مع اسم الإيداع.

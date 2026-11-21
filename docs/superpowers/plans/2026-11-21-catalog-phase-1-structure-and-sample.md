# الأصناف، المرحلة 1: البنية الجديدة والعيّنة الصغيرة — خطة التنفيذ

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**الهدف:** يُستورد كتالوج إتقان بالشكل الجديد:
- التصنيف هو الماركة.
- الصنف هو المقطع مرة واحدة.
- اللون صنف ابن.
- سعر الكيلو على (التصنيف × اللون).

ويُستورد بعيّنة صغيرة بخيار `--sample`.

**المعمارية:**
- جداول جديدة في وحدة Items: `items_finishes` و`items_category_finishes` و`items_category_finish_prices`، وعمودان جديدان على جدولين قائمين.
- **اقتراح ربط التصنيفات القديمة:** في Support، وهو منطق نقي قابل للاختبار، ويُكتب إلى CSV بأمر.
- **الاستيراد:** في `Services/Itqan/CatalogImporter`، ويأخذ صفوفًا (iterable)، فيُختبر دون ملفات إتقان.
- **المرحلة `ItqanCatalogStage`:** تغلّف المستورد، وتأخذ مكان المرحلة `items` في `ItqanStages`.
- **مرحلة `prices`:** تُوجَّه عبر `legacy_links`.
- **لا يُمسّ في هذه المرحلة:** `main_product_id` و`MainProductLinker` و`LegacyItemsImporter`. يُحذفون في المرحلة 4.

**التقنيات:** Laravel 12، nwidart modules، PHPUnit على MySQL، PostgreSQL على السيرفر، قارئ MyISAM الموجود `ItqanSource`.

**التصميم:** [docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md](../specs/2026-11-21-items-catalog-redesign-design.md)، والأقسام 2 و5.1–5.4 و6 منه. يُقرأ مع [تصميم نقل إتقان](../specs/2026-11-17-itqan-migration-design.md) §7.

## القيود العامة

- اتباع المهارة `.claude/skills/laravel`: الكيانات في `Entities/`، و`$fillable`، والجدول من `config('items.table_prefix')`، والمهاجرات محروسة بـ `Schema::hasTable` / `hasColumn`.
- **الأعمدة والمفاتيح:**
  - المبالغ `decimal(18,4)`.
  - أسماء المفاتيح الأجنبية والفهارس قصيرة وصريحة، بالبادئة `items_`.
- **التوافق مع المحركين:**
  - الكود يعمل على PostgreSQL وMySQL.
  - الكتابة الجماعية عبر `DB::table()->upsert()`.
  - أي SQL خاص بمحرك يمر عبر `Modules\Core\Support\SqlDialect`.
- **الاختبارات:** `vendor/bin/phpunit -c phpunit.mysql.xml --no-coverage <path> [--filter <name>]`. الملف الواحد يستغرق قرابة 3 دقائق بسبب `RefreshDatabase`.
- لا يُشغَّل `migrate` على قاعدة التطوير `crownsystem_itqan_sample` ولا على السيرفر. التشغيل الفعلي في المهمة 6 على قاعدة محلية جديدة منفصلة.
- `php -l` على كل ملف PHP معدّل.
- **الإيداع:** يحتاج موافقة صاحب النظام. اسأله مرة واحدة في البداية عن الإيداع على فرع `catalog-redesign`.
- **ملفات إتقان محليًا:** `C:\Users\actc\OneDrive\Desktop\almamoun2015`، وتُمرَّر بـ `--path`.

## محاور المراجعة

1. **إعادة تشغيل الاستيراد مرتين:** صفر سجلات مكررة في التصنيفات والألوان والأصناف والروابط. الاختبار في المهمة 4.
2. **اختلاف الكتابة:** أسماء بالهمزة أو بدونها (أ/إ/ا)، أو بمسافات مكررة أو طرفية، هي الصنف نفسه. الاختبار في المهمة 2 (التوحيد) والمهمة 4 (التجميع).
3. **اسم واحد بوزنين مختلفين في الماركة نفسها:** صنفان أبوان، وسطر في `exceptions.csv`، لا دمج صامت. الاختبار في المهمة 4.
4. **تصنيف قديم اسمه «حذف» أو غير فعال:** لا يُنقل شيء منه، وكل صنف فيه سطر في `items-skipped.csv`. الاختبار في المهمة 4.
5. **صنف قديم تحت تصنيف فرعي** (مثل `000200349` «قطع الوبين» تحت `00020` «قطع»): يقع في التصنيف ذي المستويين «قطع/قطع الوبين»، وسعره بالوحدة. الاختبار في المهمة 4.

---

### المهمة 1: الجداول والكيانات

**الملفات:**
- إنشاء: `Modules/Items/database/migrations/2026_11_21_000001_create_items_finishes_table.php`
- إنشاء: `Modules/Items/database/migrations/2026_11_21_000002_create_items_category_finishes_tables.php`
- إنشاء: `Modules/Items/database/migrations/2026_11_21_000003_add_finish_and_pricing_type_columns.php`
- إنشاء: `Modules/Items/Entities/Finish.php`، و`CategoryFinish.php`، و`CategoryFinishPrice.php`
- تعديل: `Modules/Items/Entities/Product.php`، بإضافة `finish_id` إلى `$fillable` والعلاقة `finish()`
- تعديل: `Modules/Items/Entities/Category.php`، بإضافة `pricing_type` إلى `$fillable` والعلاقة `categoryFinishes()`
- اختبار: `Modules/Items/tests/Unit/CatalogSchemaTest.php`

**الواجهات:**
- **ينتج — الجداول والأعمدة:**

  | الجدول | الأعمدة |
  |---|---|
  | `items_finishes` | `id`، `name` string(150) unique، `code` string(50) nullable، `is_system` bool default false، `is_active` bool default true، `sort_order` uint default 0، `created_by`، softDeletes، timestamps |
  | `items_category_finishes` | `id`، `category_id` FK cascade، `finish_id` FK restrict، `legacy_code` string(50) nullable index، `is_active` bool، timestamps، unique(`category_id`, `finish_id`) باسم `items_cat_fin_unique` |
  | `items_category_finish_prices` | `id`، `category_finish_id` FK cascade، `price_list_id` FK cascade، `sell_price` و`buy_price` decimal(18,4) default 0، `source_price_list_id` FK nullable nullOnDelete، `source_factor` decimal(18,6) nullable، `created_by`، timestamps، unique(`category_finish_id`, `price_list_id`) باسم `items_cat_fin_price_unique` |
  | `items_products` | + `finish_id` FK nullable nullOnDelete، index |
  | `items_categories` | + `pricing_type` string(20) default `'simple'` |

- **ينتج — بيانات أولية:** صف «بلا لون» بـ `is_system = true` و`code = 'none'`. تُدرجه مهاجرة الإنشاء إن لم يوجد، لأنها بيانات إعداد يجب أن توجد بعد النشر.
- **ينتج — الكيانات:**
  - `Finish::system(): Finish`: يعيد صف «بلا لون».
  - `Finish::options(): array`.
  - `CategoryFinish::category(): BelongsTo`، و`finish(): BelongsTo`، و`prices(): HasMany`.
  - `CategoryFinishPrice::categoryFinish(): BelongsTo`، و`priceList(): BelongsTo`.
  - `Product::finish(): BelongsTo`.
  - `Category::categoryFinishes(): HasMany`.

- [ ] **الخطوة 1: اكتب الاختبار الفاشل** `CatalogSchemaTest`:
  - `test_system_finish_exists_after_migration`: `Finish::system()->name === 'بلا لون'`، و`is_system === true`.
  - `test_category_finish_is_unique_per_category_and_finish`: إدراج (تصنيف، لون) مرتين يرمي `UniqueConstraintViolationException`.
  - `test_kg_prices_belong_to_category_finish`: إنشاء `CategoryFinishPrice` بـ `sell_price = 11` و`buy_price = 10` لقائمة سعر. `$categoryFinish->prices()->first()->sell_price == '11.0000'`.
  - `test_product_has_finish`: صنف بـ `finish_id`، و`$product->finish->is($finish)`.
- [ ] **الخطوة 2: شغّله وتأكد أنه يفشل** لعدم وجود الكيانات.
  `vendor/bin/phpunit -c phpunit.mysql.xml --no-coverage Modules/Items/tests/Unit/CatalogSchemaTest.php`
- [ ] **الخطوة 3: اكتب المهاجرات الثلاث والكيانات.**
  - انسخ نمط `CustomerGroup` و`create_accounting_customer_groups_table`.
  - تعليق عربي أعلى كل مهاجرة يشرح سبب الجدول، بالإحالة إلى التصميم §5.2 و5.3.
  - الكيانات بـ `LogsActivity` ما عدا `CategoryFinishPrice`.
- [ ] **الخطوة 4: شغّل الاختبار وتأكد أنه ينجح.** المتوقع: `OK (4 tests)`.
- [ ] **الخطوة 5: `php -l` على الملفات ثم الإيداع.**
  `git commit -m "feat(items): finishes and category×finish price tables"`

---

### المهمة 2: توحيد الأسماء واقتراح ربط التصنيفات

**الملفات:**
- إنشاء: `Modules/Items/Support/Itqan/ItqanNames.php`
- إنشاء: `Modules/Items/Support/Itqan/CategoryMapEntry.php` (readonly DTO)
- إنشاء: `Modules/Items/Support/Itqan/CategoryMapProposer.php`
- اختبار: `Modules/Items/tests/Unit/CategoryMapProposerTest.php`، وهو `PHPUnit\Framework\TestCase` بلا قاعدة بيانات.

**الواجهات:**
- **ينتج — `ItqanNames`:**
  - `ItqanNames::normalize(string $name): string`:
    - أ/إ/آ ← ا.
    - المسافات المتعددة ← مسافة واحدة.
    - trim.
  - `ItqanNames::isAluminum(string $name): bool`: يبدأ الاسم الموحَّد بـ «المنيوم» أو «الومنيوم».
- **ينتج — `CategoryMapEntry`:**

  ```php
  final class CategoryMapEntry {
      public function __construct(
          public string $legacyCode, public string $legacyName, public int $items,
          public string $action,        // 'keep' | 'skip'
          public string $categoryPath,  // 'ألمنيوم/الوبين' او 'قطع/قطع الوبين'
          public ?string $finish,       // null لغير الالمنيوم
          public string $pricingType,   // 'weight' | 'simple'
          public string $note = '',
      ) {}
  }
  ```

- **ينتج — `CategoryMapProposer`:**
  - `CategoryMapProposer::propose(array $nodes): array<string, CategoryMapEntry>`.
  - `$nodes` مصفوفة صفوف `['ItemId','ItemString','ItemParent','isActive','items' => int]` لكل عقدة تصنيف، الجذرية والفرعية.
  - **المفتاح:** `legacyCode`.
  - **عقدة جذرية ألمنيوم:**
    - الماركة هي أطول تطابق من قاموس `BRANDS`. القاموس ثابت مرتّب: «الوبين سيستم» قبل «الوبين»، و«رويال سيستم»، و«كليل سيستم» قبل «كليل»، و«الوم جولد» قبل «جولد»...، وكل القائمة في التصميم §6.1.
    - **`finish`:** ما بقي بعد الماركة، دون كلمة «لون» أو «دهان» في أوله. إن فرغ صار «بدون دهان».
    - **`categoryPath`:** `'ألمنيوم/'.$brand`.
    - **`pricingType`:** `weight`.
  - **عقدة جذرية غير ألمنيوم:**
    - **`categoryPath`:** اسمها.
    - **`finish`:** `null`.
    - **`pricingType`:** `weight` إن احتوى اسمها «بالوزن»، وإلا `simple`.
  - **عقدة فرعية:** `categoryPath` هو مسار أبيها ثم `'/'` ثم اسمها، و`finish` و`pricingType` من أبيها.
  - **التخطّي (`skip`):** إن طابق الاسم كلمات `JUNK`، أو كان `isActive === 'N'`، أو كان رقميًا فقط. الكلمات: حذف، حدف، غلط، تعديل، احتياط، سكراب، `؟؟؟`، `RenameIt`، «ليس للاستعمال»، «ليس للاستخدام». و`note` = السبب.
  - **ماركة غير معروفة:** `action = keep`، و`categoryPath = 'ألمنيوم/'.$remainder`، و`finish = 'بدون دهان'`، و`note = 'brand?'` لتظهر لصاحب النظام في المراجعة.

- [ ] **الخطوة 1: اكتب الاختبار الفاشل**، بأمثلة حقيقية من إتقان:

  | الاسم | الحقل | القيمة المتوقعة |
  |---|---|---|
  | `'المنيوم الوبين بدون دهان'` (00009) | `categoryPath` | `'ألمنيوم/الوبين'` |
  | | `finish` | `'بدون دهان'` |
  | | `pricingType` | `'weight'` |
  | `'المنيوم الوبين لون رال'` | `finish` | `'رال'` |
  | `'المنيوم الوبين سيستم جلوسي'` | `categoryPath` | `'ألمنيوم/الوبين سيستم'` |
  | | `finish` | `'جلوسي'` |
  | `'ألمنيوم رويال سيستم ميش'` | `categoryPath` | `'ألمنيوم/رويال سيستم'` (توحيد الهمزة) |
  | `'المنيوم  سابق 2'` | `action` | `keep` |
  | | `note` | `'brand?'` |
  | `'حذفف'` | `action` | `skip` |
  | `'000000000'` | `action` | `skip` |
  | `'كوشوك تيرمو بالوزن'` | `categoryPath` | `'كوشوك تيرمو بالوزن'` |
  | | `finish` | `null` |
  | | `pricingType` | `'weight'` |
  | `'قطع الوبين'` (000200349، أبوه 00020 «قطع») | `categoryPath` | `'قطع/قطع الوبين'` |
  | | `pricingType` | `'simple'` |

  وأيضًا: `ItqanNames::normalize('ثابت  مجرين W45 ') === 'ثابت مجرين W45'`.
- [ ] **الخطوة 2: شغّله وتأكد أنه يفشل.**
- [ ] **الخطوة 3: اكتب الفئات الثلاث.** القاموس والكلمات ثوابت في `CategoryMapProposer`، في موضع واحد.
- [ ] **الخطوة 4: شغّله وتأكد أنه ينجح.**
- [ ] **الخطوة 5: الإيداع.**
  `git commit -m "feat(items): propose legacy category → brand/finish map"`

---

### المهمة 3: ملف الربط وأمر `itqan:category-map`

**الملفات:**
- إنشاء: `Modules/Items/Support/Itqan/CategoryMapFile.php`
- إنشاء: `Modules/Items/Console/Commands/ItqanCategoryMap.php`، ويُسجَّل في `ItemsServiceProvider` مع الأوامر الأخرى.
- إنشاء: `Modules/Items/Support/Itqan/ItqanCategoryNodes.php`
- اختبار: `Modules/Items/tests/Unit/CategoryMapFileTest.php`

**الواجهات:**
- **يستهلك:** `CategoryMapProposer::propose` و`CategoryMapEntry` من المهمة 2.
- **ينتج — `CategoryMapFile`:**
  - `CategoryMapFile::defaultPath(): string` = `storage_path('app/itqan/category-map.csv')`.
  - `CategoryMapFile::write(string $path, array $entries): void`:
    - CSV بترميز UTF-8 مع BOM، ليفتحه Excel بالعربية.
    - الأعمدة بالترتيب: `legacy_code,legacy_name,items,action,category_path,finish,pricing_type,note`.
  - `CategoryMapFile::read(string $path): array<string, CategoryMapEntry>`:
    - يرمي `RuntimeException` عند عمود ناقص، أو `action` غير `keep`/`skip`، أو `pricing_type` غير `weight`/`simple`.
- **ينتج — `ItqanCategoryNodes`:** `ItqanCategoryNodes::fromRows(iterable $itemRows): array`.
  - يبني عقد التصنيفات مع عدد أصنافها من صفوف `items`.
  - **العقدة:** كل صف له أبناء.
  - **عدد الأصناف:** عدد الأوراق تحته مباشرة.
  - تستعمله المهمتان 3 و6.
- **ينتج — الأمر:** `itqan:category-map {--path=} {--output=} {--force}`.
  - يقرأ `items` عبر `ItqanSource::fromConfig($path)`، ثم يقترح، ثم يكتب.
  - إذا كان الملف موجودًا لا يكتب فوقه دون `--force`، لأن صاحب النظام قد يكون عدّله.

- [ ] **الخطوة 1: اكتب الاختبار الفاشل** `CategoryMapFileTest`:
  - `test_round_trip_keeps_entries`: `write` ثم `read` يعيد المدخلات نفسها، بما فيها `finish = null`.
  - `test_read_rejects_unknown_action`: يرمي `RuntimeException`.
  - `test_nodes_count_direct_leaves`: ثلاثة صفوف (عقدة `00009` وورقتان تحتها) تعطي `['00009' => items 2]`.
- [ ] **الخطوة 2: شغّله وتأكد أنه يفشل.**
- [ ] **الخطوة 3: اكتب `CategoryMapFile` و`ItqanCategoryNodes` والأمر.**
- [ ] **الخطوة 4: شغّل الاختبار ليمر، ثم الأمر على ملفات إتقان المحلية:**
  `php artisan itqan:category-map --path="C:\Users\actc\OneDrive\Desktop\almamoun2015" --output=storage/app/itqan/category-map.csv`
  المتوقع: صف لكل عقدة تصنيف (447 جذرية مع فروعها)، و`00009` بمسار «ألمنيوم/الوبين» ولون «بدون دهان».
- [ ] **الخطوة 5: الإيداع.** ملف CSV نفسه لا يُودَع، لأن `storage` خارج git.
  `git commit -m "feat(items): itqan:category-map command"`

---

### المهمة 4: المستورد الجديد `CatalogImporter`

**الملفات:**
- إنشاء: `Modules/Items/Services/Itqan/CatalogImporter.php`
- اختبار: `Modules/Items/tests/Unit/CatalogImporterTest.php`

**الواجهات:**
- **يستهلك:**
  - `CategoryMapEntry` و`ItqanNames` (المهمة 2).
  - `Finish` و`CategoryFinish` (المهمة 1).
  - `Modules\Core\Support\Itqan\LegacyLinks::remember/map`.
  - `Modules\Items\Support\ItemCode`.
  - `Modules\Items\Support\LegacyItemFields`، لقراءة الوزن من Prop1 والطول من Prop2 كما يفعل `LegacyItemsImporter`.
- **ينتج:**

  ```php
  public function import(
      iterable $itemRows,            // صفوف items بأعمدة LegacyItemsImporter::LEGACY_COLUMNS
      array $map,                    // array<string, CategoryMapEntry> بمفتاح رقم العقدة القديمة
      array $usedLegacyIds,          // array<string, true> الاوراق التي تُنشأ لها ابناء
      bool $allFinishes,             // true = ابن لكل لون موجود (للعيّنة)
      bool $dryRun,
      ?callable $log = null,
  ): array                           // التقرير: مفتاح => رقم
  ```

  - **مفاتيح التقرير:** `categories_created`، `finishes_created`، `category_finishes_created`، `parents_created`، `children_created`، `legacy_leaves`، `linked`، `skipped`، `exceptions`.
  - **الملفات:** يكتب `storage/app/itqan/items-skipped.csv` (`legacy_code,name,reason`) و`storage/app/itqan/exceptions.csv` (`category_path,name,legacy_codes,weights,barcodes`).
- **الخوارزمية:** القرارات التي لا يحددها الاختبار.
  1. **التصنيفات:**
     - لكل `categoryPath` فريد في المدخلات ذات `keep`، يُنشأ أو يُعثر على التصنيف وأبيه.
     - **الربط:** `legacy_links` بالمفتاح `catalog-categories` والقيمة المسار.
     - **الرمز:** يولَّد بعد أكبر رقم جذري قديم. الجذر الأول = `str_pad(max(أرقام جذور إتقان ذات 5 خانات) + 1, 5, '0')`، والفروع عبر `ItemCode::nextChild`. هذا يمنع تصادم رموز الأصناف الجديدة مع أرقام إتقان المحفوظة في `sku` الأبناء.
     - **الاسم:** في `items_category_translations` بلغة `ar`. الـ slug من `Str::slug` مع اللاحقة `-cat-<code>`.
     - **`pricing_type`:** من أول عقدة تُنشئ التصنيف.
  2. **الألوان:** لكل `finish` غير `null` يُنشأ صف `items_finishes` بالاسم، أو يُعثر عليه.
  3. **مجموعة السعر:**
     - لكل عقدة ألمنيوم `keep` يُنشأ `CategoryFinish(category, finish, legacy_code)`.
     - لعقدة غير ألمنيوم تسعيرها `weight` يُنشأ `CategoryFinish(category, Finish::system())`.
     - **الربط:** `legacy_links('items', CategoryFinish::class)` من رقم العقدة إلى معرّفها. وعقد غير الألمنيوم تُربط بـ `Category::class`.
  4. **الأوراق (الأصناف القديمة):**
     - ورقة تحت عقدة `skip` أو `isActive = 'N'`: تُكتب في items-skipped.
     - ما سواها يُجمَّع بالمفتاح (المسار، `ItqanNames::normalize(name)`، الباركود الأول قبل `█`، الوزن `round(Prop1, 3)`).
     - **التعارض:** إن وُجد اسم موحَّد واحد في المسار نفسه بأكثر من مفتاح، فكل مفتاح صنف أب، وسطر واحد في exceptions.
  5. **الآباء:**
     - صنف لكل مجموعة: الاسم بالعربية، والباركود، و`weight_per_meter`، و`length`، و`pricing_type` من العقدة، والتصنيف عبر الجدول الوسيط `items_products_categories`.
     - **`sku`:** `ItemCode::nextChild(رمز التصنيف)`.
     - **`finish_id`:** `Finish::system()` للموزون بلا لون (C‑3)، وإلا `null`.
  6. **الأبناء:** لكل ورقة في مجموعة ألمنيوم إن كانت في `$usedLegacyIds` أو كان `$allFinishes`:
     - صنف بـ `parent_id` = الأب، و`finish_id` = لون عقدتها، و`sku` = رقم إتقان.
     - نسخة من الوزن والطول والتصنيف و`pricing_type`، ليعمل الكود الحالي إلى المرحلة 2.
     - الاسم «اسم الأب - اسم اللون».
     - إن تكرر رقم الورقة نفسها في لونين (لا يحدث في إتقان)، فالأول فقط.
  7. **الروابط:** `legacy_links('items', Product::class)` لكل ورقة منقولة، إلى الابن إن أُنشئ، وإلا إلى الأب.
  8. **الكتابة:** كل شيء داخل `DB::transaction` بدفعات 500 عبر `upsert`، بمفاتيح طبيعية:
     - `slug` للتصنيفات والأصناف.
     - `name` للألوان.
     - (`category_id`, `finish_id`) لمجموعة السعر.

     فإعادة التشغيل تحدّث ولا تكرر.

- [ ] **الخطوة 1: اكتب الاختبار الفاشل** `CatalogImporterTest`، ببيانات صغيرة مكتوبة في الاختبار. خريطة يدوية فيها:
  - `00009` الوبين/بدون دهان.
  - `00001` الوبين/ابيض.
  - `00035` skip.
  - `00020` «قطع» مع فرعه `000200349`.
  - `00018` «كوشوك تيرمو بالوزن».

  والاختبارات:
  - `test_same_profile_in_two_colours_becomes_one_parent_with_two_children`:
    - الأوراق `00009001` و`00001001` باسم «ثابت مجرين W45» وباركود `809712` ووزن 1.267، و`$allFinishes = true`.
    - المتوقع: صنف أب واحد له ابنان. `sku` الابنين `00009001` و`00001001`، و`finish->name` `'بدون دهان'` و`'ابيض'`.
  - `test_unused_leaf_links_to_parent_without_child`:
    - `$allFinishes = false`، و`$usedLegacyIds = ['00009001' => true]`.
    - المتوقع: ابن واحد. ورابط `00001001` يشير إلى الأب.
  - `test_names_differing_only_in_hamza_and_spaces_group_together`:
    - «أنبوب  10 مم» و«انبوب 10 مم»، بالوزن والباركود نفسيهما.
    - المتوقع: أب واحد.
  - `test_same_name_different_weight_makes_two_parents_and_an_exception`:
    - «يو رفيع» بوزن 0.151 ثم 0.160 في الماركة نفسها.
    - المتوقع: أبوان، و`report['exceptions'] === 1`، وexceptions.csv فيه سطر واحد.
  - `test_junk_and_inactive_leaves_are_skipped_with_reason`:
    - ورقة تحت `00035`، وورقة `isActive = 'N'`.
    - المتوقع: لا صنف لهما، وitems-skipped.csv فيه سطران بالسببين.
  - `test_subcategory_leaf_lands_in_two_level_category_priced_per_unit`:
    - ورقة تحت `000200349`.
    - المتوقع: أبوها في تصنيف «قطع الوبين» تحت «قطع»، و`pricing_type = 'simple'`، و`finish_id = null`، ولا أبناء.
  - `test_weight_item_without_colour_uses_system_finish`:
    - ورقة تحت `00018`.
    - المتوقع: الأب بـ `finish_id = Finish::system()->id`، و`CategoryFinish` لتصنيفه مع «بلا لون».
  - `test_running_twice_creates_nothing_new`:
    - المتوقع: عدد صفوف التصنيفات والألوان ومجموعات السعر والأصناف و`legacy_links` نفسه بعد التشغيل الثاني.
  - `test_category_codes_start_after_legacy_roots`:
    - مع عقد جذرية حتى `00447`.
    - المتوقع: أول تصنيف جديد رمزه `00448`، ولا `sku` أب يساوي أي `sku` ابن.
- [ ] **الخطوة 2: شغّله وتأكد أنه يفشل.**
- [ ] **الخطوة 3: اكتب `CatalogImporter`.**
  - تعليق عربي في رأس الفئة يلخص القواعد، بالإحالة إلى التصميم §6.2 والقرار C‑3.
  - كتابة CSV عبر `CategoryMapFile` أو مساعد صغير مشترك، لا نسخ.
- [ ] **الخطوة 4: شغّله وتأكد أنه ينجح.**
- [ ] **الخطوة 5: الإيداع.**
  `git commit -m "feat(items): catalog importer (profiles + colour children)"`

---

### المهمة 5: الأسعار عبر الروابط

**الملفات:**
- تعديل: `Modules/Items/Services/Itqan/ItqanPricesStage.php`. بدل `productIdsBySku` تُستعمل الروابط. حدود الدالة الحالية: `run` من السطر 40 تقريبًا إلى 130.
- اختبار: `Modules/Items/tests/Unit/ItqanPricesRoutingTest.php`

**الواجهات:**
- **يستهلك:**
  - `legacy_links('items', CategoryFinish::class)` و`legacy_links('items', Product::class)` من المهمة 4.
  - `CategoryFinishPrice` من المهمة 1.
- **ينتج:** دالة ساكنة نقية `ItqanPricesStage::route(array $row, array $finishLinks, array $productLinks): array{target: 'kg'|'product'|'missing', id: ?int}`.
  - **سطر رقمه عقدة ألمنيوم:** يُكتب في `items_category_finish_prices`.
    - **مفتاح الكتابة:** (`category_finish_id`, `price_list_id`).
    - **الأعمدة:** `sell_price` و`buy_price` من `SellPrice` و`BuyPrice`.
  - **سطر رقمه ورقة مربوطة:** إلى `ProductPrice` على الصنف المربوط، كما اليوم.
  - **ورقتان قديمتان مربوطتان بالأب نفسه:** يُكتب السعر الأخير، ويُعدّ في التقرير `prices_merged_into_parent`.
  - **ما ليس له رابط:** يُعدّ في `prices_for_missing_items`.
- **تقرير جديد:** `kg_prices_written`.

- [ ] **الخطوة 1: اكتب الاختبار الفاشل:**
  - `test_category_node_price_routes_to_kg_price`: `route(['ItemId' => '00009', ...], ['00009' => 5], [])` يعطي `['target' => 'kg', 'id' => 5]`.
  - `test_leaf_price_routes_to_product`.
  - `test_unlinked_price_is_missing`.
  - `test_stage_writes_kg_prices`: بقاعدة بيانات وروابط مزروعة يدويًا، يُكتب `11.0000` و`10.0000` للقائمة.
- [ ] **الخطوة 2: شغّله وتأكد أنه يفشل.**
- [ ] **الخطوة 3: عدّل المرحلة.** القوائم (`importLists`) كما هي دون تغيير.
- [ ] **الخطوة 4: شغّله وتأكد أنه ينجح.** شغّل أيضًا `Modules/Items/tests/Unit` كاملة للتأكد أن اختبارات المستورد القديم لم تنكسر.
- [ ] **الخطوة 5: الإيداع.**
  `git commit -m "feat(items): route itqan prices via legacy links (kg prices on category×finish)"`

---

### المهمة 6: المرحلة `ItqanCatalogStage` والعيّنة والتشغيل المحلي

**الملفات:**
- إنشاء: `Modules/Items/Services/Itqan/ItqanCatalogStage.php`، وهو `implements ItqanImportStage`.
- إنشاء: `Modules/Items/Support/Itqan/CatalogSample.php`
- تعديل: `Modules/Items/Providers/ItemsServiceProvider.php:57`، لتسجيل `'items'` بالفئة الجديدة. `ItqanItemsStage` يبقى ملفًا غير مسجَّل حتى المرحلة 4.
- تعديل: `Modules/Core/Console/Commands/ItqanImport.php`:
  - خياران جديدان: `--sample` و`--all-finishes`.
  - يضعهما في `config(['core.itqan.sample' => ..., 'core.itqan.all_finishes' => ...])` قبل التشغيل، لأن واجهة المرحلة ثابتة.
- اختبار: `Modules/Items/tests/Unit/CatalogSampleTest.php`

**الواجهات:**
- **يستهلك:** `CatalogImporter::import` (المهمة 4)، و`CategoryMapFile::read/defaultPath` و`ItqanCategoryNodes::fromRows` (المهمة 3)، و`CategoryMapProposer::propose` (المهمة 2).
- **ينتج — `CatalogSample::select(array $itemRows): array`:** يعيد الصفوف المختارة فقط، بالقواعد التالية:
  - **العقد:** `00009` و`00001` و`00002` و`00043`، والعقد الأب للفروع المختارة.
  - **المقاطع:** أول 30 اسمًا موحَّدًا من أوراق `00009` بترتيب `ItemId`، ومنها `00009001`، مع أوراقها بالاسم نفسه في الألوان الثلاثة الأخرى.
  - **الإكسسوارات:** أول 20 ورقة من `000200349`.
  - **المطاط:** أول ورقة من `00018`.
  - **التصنيف الملغى:** أول ورقة من `00035`، لتظهر في items-skipped.
- **ينتج — `ItqanCatalogStage::run`:**
  - **الخريطة:** يقرأ `category-map.csv` إن وُجد، وإلا يقترحها في الذاكرة ويطبع تنبيهًا.
  - **العيّنة:** يطبّق `CatalogSample` إن كان `sample` مفعّلًا. في العيّنة `allFinishes = true`.
  - **الأوراق المستخدمة:** من `invoicesdet.theItemId` عبر `$source->rows('invoicesdet', ['theItemId'])`.
  - يستدعي المستورد، ثم `app(ItemsSearchIndex::class)->rebuild()` مرة واحدة في النهاية.
  - `dependsOn()` يعيد `[]`.

- [ ] **الخطوة 1: اكتب الاختبار الفاشل** `test_sample_selects_w45_in_four_colours_and_fixed_extras`:
  - **المدخلات:** صفوف مصنوعة فيها أوراق كافية.
  - **المتوقع:** النتيجة فيها `00009001` و`00001001` و`00002001` و`00043001`، و20 ورقة من `000200349`، وورقة من `00018`، وورقة من `00035`، ولا شيء من `00003`.
- [ ] **الخطوة 2: شغّله وتأكد أنه يفشل.**
- [ ] **الخطوة 3: اكتب `CatalogSample` و`ItqanCatalogStage`، وعدّل التسجيل والأمر.**
- [ ] **الخطوة 4: شغّل `Modules/Items/tests` و`Modules/Core/tests/Unit/ItqanStagesTest.php`.** المتوقع: نجاح الكل.
- [ ] **الخطوة 5: التشغيل الفعلي على قاعدة محلية منفصلة** (لا تُمسّ `crownsystem_itqan_sample`):

  ```
  mysql -uroot -e "CREATE DATABASE crownsystem_catalog_sample CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  DB_DATABASE=crownsystem_catalog_sample php artisan migrate --seed --force
  DB_DATABASE=crownsystem_catalog_sample php artisan itqan:import items prices --sample --path="C:\Users\actc\OneDrive\Desktop\almamoun2015"
  ```

  **المتوقع في التقرير:**
  - `categories_created`: «ألمنيوم» و«الوبين» و«قطع» و«قطع الوبين» و«كوشوك تيرمو بالوزن».
  - `finishes_created`: 4.
  - `parents_created` قرابة 52.
  - `children_created` قرابة 120.
  - `skipped` ≥ 1.
  - `kg_prices_written` ≥ 4.

  ثم تحقق باستعلام:
  - صنف «ثابت مجرين W45» له 4 أبناء.
  - سعر (الوبين × بدون دهان) في قائمة الكاش `sell_price = 11`.
- [ ] **الخطوة 6: الإيداع.** ثم أبلغ صاحب النظام بالنتيجة، وبأن تحويل `.env` المحلي إلى القاعدة الجديدة قراره هو.
  `git commit -m "feat(items): catalog stage with --sample"`

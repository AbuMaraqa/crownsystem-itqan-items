# الأصناف، المرحلة 1ب: المتغيرات، ومرآة المتجر، والتجميع الصحيح — خطة التنفيذ

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**الهدف:** المستورد يجمّع المقاطع على مستوى الكل فيقارب عدد الآباء 11.7 ألفًا. يحمل كل متغيّر مجموعة سعره ولونه، ويظهر اللون للمتجر كسمة. وتُسدّ ملاحظات المراجعة.

**المعمارية:** تعديل `CatalogImporter` و`CategoryMapProposer` و`CategoryMapFile` و`ItqanCatalogStage` من المرحلة 1، وثلاث مهاجرات جديدة.

**التقنيات:** Laravel 12، PHPUnit على MySQL، وPostgreSQL على السيرفر.

**التصميم:** [§10 من وثيقة التصميم](../specs/2026-11-21-items-catalog-redesign-design.md)، والقرارات R‑1 إلى R‑12.

## القيود العامة

- قيود خطة المرحلة 1 كلها سارية: [2026-11-21-catalog-phase-1-structure-and-sample.md](2026-11-21-catalog-phase-1-structure-and-sample.md).
- الحالات `ProductStatus::PUBLISHED` و`CategoryStatus::ACTIVE` من التعدادات، لا أرقام حرفية.

## محاور المراجعة

1. **مقطع في مجموعتي سعر من نظامين** (سبير × رال، ورويال × بدون دهان): أب واحد وابنان، ولكل ابن `category_finish_id` الخاص بمجموعته. الاختبار في المهمة 4.
2. **ملف ربط فتحه Excel** فحذف الأصفار (`9` بدل `00009`): تتوقف المرحلة برسالة، ولا تكتب شيئًا. الاختبار في المهمة 3.
3. **عقدتان في المسار نفسه لونهما يختلف كتابة فقط** («FINE IRON» و«fine Iron»): ابن واحد. الاختبار في المهمة 4.
4. **إعادة التشغيل بعد نقل عقدة إلى `skip`:** أبناؤها يُعطَّلون، ولا يُحذفون، وتُعدّ في التقرير. الاختبار في المهمة 4.
5. **متغيّر لون جديد:** له صف في `items_product_attribute_value` بقيمة سمة «اللون» المطابقة لونه. الاختبار في المهمة 4.

---

### Task 1 (المهمة 1): الأعمدة والسمة النظامية

**الملفات:**
- إنشاء: `Modules/Items/database/migrations/2026_11_22_000001_add_category_finish_and_colour_attribute.php`
- تعديل: `Entities/Product.php`، بإضافة `category_finish_id` إلى `$fillable` والعلاقة `categoryFinish()`.
- تعديل: `Entities/Finish.php`، بإضافة `attribute_value_id` والعلاقة `attributeValue()`، والدالة `Finish::colourAttributeId(): int`.
- اختبار: إضافة إلى `tests/Unit/CatalogSchemaTest.php`.

**ما تنتجه المهاجرة:**
- `items_products.category_finish_id`: FK اختياري إلى `items_category_finishes`، مع `nullOnDelete` وفهرس.
- `items_finishes.attribute_value_id`: FK اختياري إلى `items_attribute_values`، مع `nullOnDelete`.
- **السمة النظامية:** `items_attributes` بـ slug `colour` والنوع `AttributeType::COLOR`، وترجمتها العربية «اللون»، تُنشأ إن لم تكن موجودة.
- **قيمة «بلا لون»:** قيمة سمة لها تُنشأ وتُربط بها.

**الاختبارات:**
- `test_colour_attribute_exists_and_system_finish_is_mirrored`: سمة slug `colour` موجودة، و`Finish::system()->attributeValue->attribute_id === Finish::colourAttributeId()`.
- `test_product_has_category_finish`.

---

### Task 2 (المهمة 2): المقترح — عقد بلا سيستم، وفروع نابكو، وكلمات الإلغاء

**الملفات:** `Support/Itqan/CategoryMapProposer.php`، واختباره `tests/Unit/CategoryMapProposerTest.php`.

**التغييرات:**
- **R‑4:** الماركة غير المعروفة تصبح `categoryPath = 'ألمنيوم'`، و`finish` = ما بعد «المنيوم» كاملًا، بعد حذف «لون» أو «دهان» من أوله، و`note = 'brand?'`.
- **كلمات الدهّانين والمورّدين:** ثابت `PAINTERS = ['دهان جلوسي اكسبرس', 'شركة الرحمن', 'عالم الالوان', 'سما']`. العقدة التي تبدأ بقية اسمها بواحد منها تُعامَل مثل R‑4، مع `note = 'painter'`.
- **R‑5:** في `child()`، إن كان اسم العقدة الفرعية يبدأ بـ«المنيوم» وأبوها ألمنيوم، تُحلّ كعقدة جذرية.
- **كلمات الإلغاء:** `JUNK` + «ليست للاستعمال» و«ليست للاستخدام».

**الاختبارات** (يُعدَّل `test_unknown_brand_is_kept_and_flagged` ليتوقع المسار «ألمنيوم» واللون «سابق 2»):
- `test_painter_node_goes_to_aluminum_root_with_full_finish`: «المنيوم دهان جلوسي اكسبرس لون ابيض» تعطي المسار `ألمنيوم`، واللون `جلوسي اكسبرس لون ابيض`.
- `test_aluminum_named_subnode_resolves_like_a_root`: «المنيوم نابكو ابيض» تحت «المنيوم نابكو» تعطي المسار `ألمنيوم/نابكو`، واللون `ابيض`.
- `test_feminine_not_for_use_is_junk`: «ليست للاستعمال خدمة دهان نابكو» تعطي `skip`.

---

### Task 3 (المهمة 3): التحقق من ملف الربط وحارس المرحلة

**الملفات:** `Support/Itqan/CategoryMapFile.php`، و`Services/Itqan/ItqanCatalogStage.php`، واختبار `tests/Unit/CategoryMapFileTest.php`.

**التغييرات:**
- **`read()`:** يرمي `RuntimeException` في حالتين:
  - `legacy_code` ليس `ctype_digit`، أو طوله أقل من 5.
  - السطر ليس UTF‑8 سليمًا (`mb_check_encoding`).

  ونص الرسالة يذكر Excel وطريقة الفتح الصحيحة.
- **`CategoryMapFile::assertMatches(array $map, array $nodes, array $leafParents): void`** يرمي في حالتين:
  - رموز `keep` في الخريطة لا يوجد منها شيء في العقد.
  - أكثر من 20% من الأوراق آباؤها خارج الخريطة.

  تستدعيه المرحلة قبل الاستيراد.

**الاختبارات:**
- `test_read_rejects_code_without_leading_zeros`.
- `test_read_rejects_invalid_utf8`.
- `test_assert_matches_rejects_mostly_unmapped_leaves`.

---

### Task 4 (المهمة 4): المستورد — التجميع العالمي، ومجموعة السعر على الابن، والمرآة، والتعطيل

**الملفات:** `Services/Itqan/CatalogImporter.php`، واختبار `tests/Unit/CatalogImporterTest.php`.

**التغييرات:**
- **التجميع (R‑1):**
  - مفتاح المجموعة: (الاسم الموحَّد، الباركود، الوزن).
  - مسار الأب: أكثر `categoryPath` تكرارًا بين أوراقه غير `ألمنيوم`. عند التساوي يُختار الأصغر أبجديًا. إن لم يوجد فالمسار هو `ألمنيوم`.
  - التصنيف «ألمنيوم» نفسه يُنشأ دائمًا إن وُجدت عقد ألمنيوم.
- **الأبناء (R‑2، R‑3، R‑11):**
  - مفتاح الابن داخل المجموعة هو معرّف `CategoryFinish` للعقدة، لا نص اللون. هذا يسدّ ملاحظة المراجعة #2.
  - يُكتب على الابن `category_finish_id` و`finish_id`، ولا يُكتب `barcode`.
  - الاسم «الأب - اللون»، أو «الأب - التصنيف اللون» إن كان مسار المجموعة غير مسار الأب.
- **التعارضات (R‑9):** إضافة مجموعات (الباركود غير الفارغ، الوزن) ذات أكثر من اسم موحَّد إلى `exceptions.csv`، بعمود `kind` قيمته `same_name` أو `same_barcode`.
- **المرآة (R‑6):**
  - كل لون بلا `attribute_value_id` تُنشأ له قيمة في سمة «اللون»، مع ترجمتها العربية، ويُربط بها.
  - لكل ابن يُكتب صف `items_product_attribute_value` بإدراج يتجاهل المكرر.
- **التعطيل (R‑10):**
  - آباء `itqan-p-*` ليسوا في الخطة الحالية يصبحون `is_active = 0`.
  - أبناء `itqan-*` لم يعد مقابلهم ابنًا في الخطة يصبحون `is_active = 0` أيضًا.
  - مفتاحان جديدان في التقرير: `parents_deactivated` و`children_deactivated`.
  - **في العيّنة لا يُعطَّل شيء**، لأنها جزء من الكل.
- **رمز الجذر (R‑12):** `withCodeFloor(int $maxLegacyRoot)`، وتمرّره المرحلة من الخريطة الكاملة قبل `restrictMap`.
- **الحالات:** من التعدادات.

**الاختبارات** (تُعدَّل اختبارات المرحلة 1 التي تفترض المسار في المفتاح):
- `test_profile_in_two_price_groups_of_different_systems_is_one_parent_with_two_children`.
- `test_children_carry_their_price_group_and_no_barcode`.
- `test_finish_spelling_variants_in_one_path_make_one_child`.
- `test_same_barcode_and_weight_with_different_names_is_reported`.
- `test_colour_child_is_mirrored_to_the_colour_attribute`.
- `test_rerun_after_skipping_a_node_deactivates_its_children`.
- `test_parent_category_is_the_most_frequent_system`.

---

### Task 5 (المهمة 5): الحقول التي كان ينقلها المستورد القديم

**الملفات:** `Services/Itqan/CatalogImporter.php`، واختبار `tests/Unit/CatalogImporterTest.php`.

**التغييرات (R‑8):**
- **على الأب:**
  - `unit_id` عبر `LegacyUnitResolver::findOrCreate(itemunit)`.
  - `shelf` من `LegacyItemFields::columns`.
  - الاسم الإنجليزي عبر `LegacyEnglishNameWriter` بالقيمة `LegacyItemFields::englishName`.
- **على البائع، أي الابن أو الأب إن لم يكن له ابن:**
  - `cost_price` من `ItemCost`.
  - `max_limit` و`min_limit_1` و`min_limit_2` من `ItemMax` و`ItemMin1` و`ItemMin2`. القيمة الفارغة أو الصفر تُترك `null`، كما في المستورد القديم.
  - الباركودات الإضافية عبر `LegacyBarcodeWriter::plan/insert`، من `LegacyItemFields::barcodes(...)['secondary']`.
- مفتاحان جديدان في التقرير: `units_created` و`barcode_rows_created`.

**الاختبارات:**
- `test_parent_gets_unit_and_english_name`.
- `test_seller_gets_cost_limits_and_secondary_barcodes`.

---

### Task 6 (المهمة 6): التشغيل الحقيقي والقياس

1. **تجربة كاملة بلا كتابة:** `itqan:category-map --force`، ثم `itqan:import items --dry-run` على ملفات إتقان المحلية.

   **المتوقع:**
   - `groups` بين 11 و13 ألفًا.
   - `exceptions` و`skipped` معروضان.
2. **العيّنة الحقيقية:** إعادة إنشاء القاعدة المحلية `crownsystem_catalog_sample`، أو تفريغ جداول الأصناف فيها، ثم `--sample`.

   **المتوقع:**
   - W45 أب واحد بأربعة أبناء، ولكل منهم `category_finish_id`.
   - لكل ابن صف سمة «اللون».
   - سعر كاش (الوبين × بدون دهان) = 11.
3. **الإيداع:** مع سطر في سجل التقدم.

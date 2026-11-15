<?php

namespace Modules\Items\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Items\Support\LegacyItemFields;
use Modules\Items\Support\Search\ItemsSearchIndex;

/**
 * تصحيح الاصناف المستوردة قبل اصلاح الاستيراد، من meta_data التي حفظها المستورد
 * (legacy_item_type و props و legacy_barcode):
 *  - يملأ وزن المتر والطول والرف اذا كانت فارغة فقط، فلا يمس تعديلاً يدوياً.
 *  - يربط وحدة الصنف من meta_data.unit، ويضيف الاسم الانجليزي من Prop6 اذا لم يكن رقماً، للفارغ فقط.
 *  - يفرغ weight فقط اذا كان يساوي Prop1 المنقول خطأً في نوع ليس Prop1 فيه وزناً.
 *  - يفصل الباركودات: يصلح products.barcode اذا كان فارغاً او كما استورده النظام
 *    (النص الكامل بفواصله)، ويضيف الباقي الى product_barcodes. واذا عُدّل يدوياً يبقى
 *    كما هو ولا تُضاف باركوداته القديمة، فقد يكون حذفها مقصوداً.
 *
 * الكتابة مباشرة في الجداول كما في المستورد، فلا يُسجَّل الآلاف في سجل النشاط،
 * ثم يُحدَّث فهرس البحث لما تغيّر.
 */
class LegacyFieldsBackfill
{
    public function __construct(
        private readonly LegacyBarcodeWriter $barcodes,
        private readonly LegacyEnglishNameWriter $englishNames,
        private readonly LegacyUnitResolver $units,
    ) {}

    /**
     * @return array<string, int|bool>
     */
    public function run(bool $apply = false, ?callable $logger = null): array
    {
        $stats = [
            'dry_run' => ! $apply,
            'products_scanned' => 0,
            'not_legacy' => 0,
            'products_changed' => 0,
            'weight_per_meter_filled' => 0,
            'length_filled' => 0,
            'shelf_filled' => 0,
            'unit_id_filled' => 0,
            'english_names_created' => 0,
            'fields_kept_existing' => 0,
            'weight_cleared' => 0,
            'weight_kept_edited' => 0,
            'primary_barcode_fixed' => 0,
            'barcode_kept_edited' => 0,
            'first_barcode_pack_quantity_ignored' => 0,
            'barcode_rows_created' => 0,
        ];

        $table = config('items.table_prefix').'products';

        DB::table($table)
            ->select(['id', 'barcode', 'weight', 'weight_per_meter', 'length', 'shelf', 'unit_id', 'meta_data'])
            ->whereNull('deleted_at')
            ->whereNotNull('meta_data')
            ->orderBy('id')
            ->chunkById(1000, function (Collection $products) use ($apply, $table, &$stats, $logger): void {
                $this->processChunk($products, $apply, $table, $stats);

                if ($logger) {
                    $logger("Scanned {$stats['products_scanned']} products...");
                }
            });

        return $stats;
    }

    /**
     * @param  array<string, int|bool>  $stats
     */
    private function processChunk(Collection $products, bool $apply, string $table, array &$stats): void
    {
        $updates = [];
        $secondaryByProduct = [];
        $englishNames = [];

        foreach ($products as $product) {
            $stats['products_scanned']++;

            $meta = json_decode((string) $product->meta_data, true);
            $type = is_array($meta) ? ($meta['legacy_item_type'] ?? null) : null;

            if (! is_string($type) || $type === '') {
                $stats['not_legacy']++;

                continue;
            }

            $props = is_array($meta['props'] ?? null) ? $meta['props'] : [];
            $changes = [];

            foreach (LegacyItemFields::columns($type, $props) as $column => $value) {
                if ($this->isEmpty($product->{$column})) {
                    $changes[$column] = $value;
                    $stats[$column.'_filled']++;
                } else {
                    $stats['fields_kept_existing']++;
                }
            }

            // التشغيل التجريبي لا يُنشئ وحدات؛ القيمة فيه للعدّ فقط ولا تُكتب.
            if ($product->unit_id === null && trim((string) ($meta['unit'] ?? '')) !== '') {
                $changes['unit_id'] = $apply ? $this->units->findOrCreate($meta['unit']) : 0;
                $stats['unit_id_filled']++;
            }

            if (($englishName = LegacyItemFields::englishName($props)) !== null) {
                $englishNames[(int) $product->id] = $englishName;
            }

            $this->repairWeight($product, $type, $props, $changes, $stats);

            $secondary = $this->repairBarcode($product, $type, $props, $meta['legacy_barcode'] ?? null, $changes, $stats);

            if ($secondary !== []) {
                $secondaryByProduct[(int) $product->id] = $secondary;
            }

            if ($changes !== []) {
                $updates[(int) $product->id] = $changes;
            }
        }

        $rows = $this->barcodes->plan($secondaryByProduct);
        $nameRows = $this->englishNames->plan($englishNames);

        $changedIds = array_unique([...array_keys($updates), ...array_column($rows, 'product_id'), ...array_column($nameRows, 'product_id')]);
        $stats['products_changed'] += count($changedIds);

        if (! $apply) {
            $stats['barcode_rows_created'] += count($rows);
            $stats['english_names_created'] += count($nameRows);

            return;
        }

        DB::transaction(function () use ($updates, $rows, $nameRows, $table, &$stats): void {
            $now = now();

            foreach ($updates as $id => $changes) {
                DB::table($table)->where('id', $id)->update($changes + ['updated_at' => $now]);
            }

            $stats['barcode_rows_created'] += $this->barcodes->insert($rows);
            $stats['english_names_created'] += $this->englishNames->insert($nameRows);
        });

        if ($changedIds !== []) {
            app(ItemsSearchIndex::class)->refreshProducts($changedIds);
        }
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $changes
     * @param  array<string, int|bool>  $stats
     */
    private function repairWeight(object $product, string $type, array $props, array &$changes, array &$stats): void
    {
        if ($product->weight === null || ! LegacyItemFields::prop1IsNotWeight($type)) {
            return;
        }

        $prop1 = trim((string) ($props['Prop1'] ?? ''));

        // المستورد القديم حفظ Prop1 بمنزلتين.
        if (is_numeric($prop1) && number_format((float) $prop1, 2, '.', '') === number_format((float) $product->weight, 2, '.', '')) {
            $changes['weight'] = null;
            $stats['weight_cleared']++;
        } else {
            $stats['weight_kept_edited']++;
        }
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $changes
     * @param  array<string, int|bool>  $stats
     * @return array<int, array{barcode: string, pack_quantity: string, is_prefix: bool, sort_order: int}>
     */
    private function repairBarcode(object $product, string $type, array $props, mixed $legacyBarcode, array &$changes, array &$stats): array
    {
        if (! is_string($legacyBarcode) || trim($legacyBarcode) === '') {
            return [];
        }

        $parsed = LegacyItemFields::barcodes($legacyBarcode, $type, $props);
        $current = trim((string) $product->barcode);
        $imported = mb_substr(trim($legacyBarcode), 0, 255);

        if ($current !== '' && $current !== $imported && $current !== $parsed['primary']) {
            $stats['barcode_kept_edited']++;

            return [];
        }

        if ($current !== (string) $parsed['primary']) {
            $changes['barcode'] = $parsed['primary'];
            $stats['primary_barcode_fixed']++;
        }

        // الباركود الرئيسي كميته 1 دائماً، فكمية عبوته القديمة تبقى في meta_data ويُنبَّه اليها.
        if ($parsed['primary'] !== null && $parsed['primary_pack_quantity'] !== '1.000000') {
            $stats['first_barcode_pack_quantity_ignored']++;
        }

        return $parsed['secondary'];
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}

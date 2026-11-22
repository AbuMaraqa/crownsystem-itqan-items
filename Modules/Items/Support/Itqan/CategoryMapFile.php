<?php

namespace Modules\Items\Support\Itqan;

use RuntimeException;

/**
 * ملف CSV لربط تصنيفات إتقان: يُكتب مقترحاً، ويعدّله صاحب النظام في Excel، ثم
 * يقرؤه النقل. UTF-8 مع BOM ليفتحه Excel بالعربية. يرفض القيم الخاطئة بدل ان ينقل بها.
 */
final class CategoryMapFile
{
    public const COLUMNS = ['legacy_code', 'legacy_name', 'items', 'action', 'category_path', 'finish', 'pricing_type', 'note'];

    private const BOM = "\xEF\xBB\xBF";

    private const PRICING_TYPES = ['weight', 'simple'];

    /** اقصر رقم عقدة في إتقان (00011): رقم اقصر يعني ان Excel حذف الأصفار. */
    private const MIN_CODE_LENGTH = 5;

    /** نسبة الأوراق بلا ربط التي يُرفض النقل فوقها: الملف لا يخص هذه البيانات. */
    private const MAX_UNMAPPED_SHARE = 0.2;

    public static function defaultPath(): string
    {
        return storage_path('app/itqan/category-map.csv');
    }

    /**
     * @param  array<string, CategoryMapEntry>  $entries
     */
    public static function write(string $path, array $entries): void
    {
        self::writeCsv($path, self::COLUMNS, array_map(fn (CategoryMapEntry $entry): array => [
            $entry->legacyCode, $entry->legacyName, $entry->items, $entry->action,
            $entry->categoryPath, $entry->finish ?? '', $entry->pricingType, $entry->note,
        ], array_values($entries)));
    }

    /**
     * كاتب CSV مشترك لتقارير النقل (الربط، المتخطّى، الاستثناءات).
     *
     * @param  array<int, string>  $header
     * @param  array<int, array<int, mixed>>  $rows
     */
    public static function writeCsv(string $path, array $header, array $rows): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot write: {$path}");
        }

        fwrite($handle, self::BOM);
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($value): string => (string) $value, $row), ',', '"', '');
        }

        fclose($handle);
    }

    /**
     * @return array<string, CategoryMapEntry>  بمفتاح رقم العقدة القديمة
     */
    public static function read(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Category map not found: {$path}");
        }

        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false) {
            fclose($handle);

            throw new RuntimeException("Category map is empty: {$path}");
        }

        $header[0] = (string) preg_replace('/^'.self::BOM.'/', '', (string) $header[0]);
        $missing = array_diff(self::COLUMNS, $header);
        if ($missing !== []) {
            fclose($handle);

            throw new RuntimeException('Category map is missing columns: '.implode(', ', $missing));
        }

        $entries = [];
        $line = 1;
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }

            if (! mb_check_encoding(implode(',', array_map('strval', $cells)), 'UTF-8')) {
                fclose($handle);

                throw new RuntimeException("Category map line {$line} is not UTF-8. Save it from Excel as \"CSV UTF-8\".");
            }

            $row = array_combine($header, array_pad($cells, count($header), ''));
            $entry = self::entry($row, $line);
            $entries[$entry->legacyCode] = $entry;
        }

        fclose($handle);

        return $entries;
    }

    /**
     * يتأكد ان الملف يخص بيانات إتقان هذه قبل النقل: رموزه موجودة في العقد، واغلب الأوراق
     * لها ربط. والا يتوقف النقل برسالة بدل ان يتخطى الأصناف بصمت.
     *
     * @param  array<string, CategoryMapEntry>  $map
     * @param  array<string, mixed>  $nodes  عقد التصنيفات بمفتاح رقمها
     * @param  array<int, string>  $leafParents  اب كل ورقة
     */
    public static function assertMatches(array $map, array $nodes, array $leafParents): void
    {
        $kept = array_keys(array_filter($map, fn (CategoryMapEntry $entry): bool => $entry->kept()));

        if ($kept !== [] && array_intersect(array_map('strval', $kept), array_map('strval', array_keys($nodes))) === []) {
            throw new RuntimeException('None of the category map codes exist in the Itqan data. Is this the right map file?');
        }

        if ($leafParents === []) {
            return;
        }

        $unmapped = count(array_filter($leafParents, fn ($parent): bool => ! isset($map[(string) $parent])));
        $share = $unmapped / count($leafParents);

        if ($share > self::MAX_UNMAPPED_SHARE) {
            throw new RuntimeException(sprintf(
                '%d%% of Itqan items have no category in the map (%d of %d). Regenerate it with itqan:category-map.',
                (int) round($share * 100), $unmapped, count($leafParents),
            ));
        }
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private static function entry(array $row, int $line): CategoryMapEntry
    {
        $code = trim((string) $row['legacy_code']);
        if (! ctype_digit($code) || strlen($code) < self::MIN_CODE_LENGTH) {
            throw new RuntimeException(
                "Category map line {$line}: legacy_code '{$code}' lost its leading zeros (Excel does this). "
                .'Open the file in Excel through Data > From Text/CSV and set legacy_code to Text.'
            );
        }

        $action = trim((string) $row['action']);
        if (! in_array($action, [CategoryMapEntry::KEEP, CategoryMapEntry::SKIP], true)) {
            throw new RuntimeException("Category map line {$line}: action must be keep or skip, got '{$action}'.");
        }

        $pricingType = trim((string) $row['pricing_type']);
        if (! in_array($pricingType, self::PRICING_TYPES, true)) {
            throw new RuntimeException("Category map line {$line}: pricing_type must be weight or simple, got '{$pricingType}'.");
        }

        $finish = trim((string) $row['finish']);

        return new CategoryMapEntry(
            $code,
            (string) $row['legacy_name'],
            (int) $row['items'],
            $action,
            trim((string) $row['category_path']),
            $finish === '' ? null : $finish,
            $pricingType,
            (string) $row['note'],
        );
    }
}

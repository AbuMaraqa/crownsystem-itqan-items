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

            $row = array_combine($header, array_pad($cells, count($header), ''));
            $entry = self::entry($row, $line);
            $entries[$entry->legacyCode] = $entry;
        }

        fclose($handle);

        return $entries;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private static function entry(array $row, int $line): CategoryMapEntry
    {
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
            trim((string) $row['legacy_code']),
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

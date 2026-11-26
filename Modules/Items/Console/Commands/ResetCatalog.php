<?php

namespace Modules\Items\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Items\Entities\Product;

/**
 * تفريغ كتالوج الاصناف قبل استيراد اتقان الجديد (يوم الانتقال، بموافقة صاحب النظام): الاصناف والتصنيفات
 * ومجموعات السعر، والمستندات التجريبية التي تستعملها (عروض، طلبيات، فواتير، مرتجعات، سندات مخزن، انتاج)
 * ودفتر المخزون، وقيود تلك المستندات. الجداول تُستنتج من المفاتيح الاجنبية؛ والمستخدمون والحسابات
 * والاعدادات محمية، ويتوقف الامر ان وصلها التفريغ.
 *
 * بلا --execute يعرض ما سيُحذف فقط.
 */
class ResetCatalog extends Command
{
    protected $signature = 'items:reset-catalog
        {--execute : Delete for real (without it, only report)}';

    protected $description = 'Empty the items catalogue and the trial documents that use it, before the new Itqan import.';

    /** مصادر قيود المستندات التي تُحذف مع مستنداتها. */
    public const DOCUMENT_JOURNAL_SOURCES = [
        'SalesInvoice', 'SalesReturn', 'PurchaseInvoice', 'PurchaseReturn', 'GoodsReceipt', 'DeliveryNote',
        'StockTransfer', 'StockAdjustment', 'ProductionOrder', 'InvoiceAdjustment',
    ];

    /** جداول لا تُفرَّغ ابداً؛ وصول التفريغ اليها يوقف الامر. */
    private const PROTECTED = [
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'settings', 'currencies', 'legacy_links', 'media', 'activity_log', 'notifications',
    ];

    public function handle(): int
    {
        $tables = $this->tablesToEmpty();
        $blocked = array_values(array_filter($tables, fn (string $table): bool => $this->isProtected($table)));

        if ($blocked !== []) {
            $this->error('Refusing: the reset would reach protected tables: '.implode(', ', $blocked));

            return self::FAILURE;
        }

        $journals = $this->documentJournalIds();
        $rows = [];

        foreach ($tables as $table) {
            $rows[] = [$table, DB::table($table)->count()];
        }

        $this->table(['Table', 'Rows'], $rows);
        $this->line('Journal entries of the documents: '.count($journals));
        $this->line('Itqan item links (legacy_links, items): '.DB::table('legacy_links')->where('source_table', 'items')->count());

        if (! $this->option('execute')) {
            $this->warn('Report only. Run with --execute to delete.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($journals): void {
            $this->deleteJournals($journals);
            DB::table('legacy_links')->where('source_table', 'items')->delete();
            DB::table('media')->where('model_type', (new Product)->getMorphClass())->delete();
        });

        $this->truncate($tables);
        $this->info('Catalogue emptied: '.count($tables).' tables, '.count($journals).' journal entries.');

        return self::SUCCESS;
    }

    /**
     * الاصناف والتصنيفات ومجموعات السعر ودفتر المخزون ورؤوس المستندات، وكل جدول يشير اليها (بالتتابع).
     *
     * @return list<string>
     */
    public function tablesToEmpty(): array
    {
        $items = config('items.table_prefix');
        $accounting = config('accounting.table_prefix');
        $seeds = array_values(array_filter([
            $items.'products', $items.'categories', $items.'category_finishes',
            $accounting.'sales_quotations', $accounting.'sales_orders', $accounting.'sales_invoices', $accounting.'sales_returns',
            $accounting.'purchase_quotations', $accounting.'purchase_orders', $accounting.'purchase_invoices', $accounting.'purchase_returns',
            $accounting.'consolidated_purchase_orders', $accounting.'goods_receipts', $accounting.'delivery_notes',
            $accounting.'stock_transfers', $accounting.'stock_adjustments', $accounting.'production_orders',
            $accounting.'stock_movements', $accounting.'stock_balances', $accounting.'stock_cost_layers', $accounting.'product_costs',
        ], fn (string $table): bool => Schema::hasTable($table)));

        $referencedBy = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                $referencedBy[$key['foreign_table']][] = $table;
            }
        }

        $found = array_fill_keys($seeds, true);
        $queue = $seeds;

        while ($queue !== []) {
            $table = array_shift($queue);

            foreach (array_unique($referencedBy[$table] ?? []) as $child) {
                if (! isset($found[$child])) {
                    $found[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        $tables = array_keys($found);
        sort($tables);

        return $tables;
    }

    private function isProtected(string $table): bool
    {
        $accounting = config('accounting.table_prefix');

        return in_array($table, self::PROTECTED, true)
            || in_array($table, [$accounting.'accounts', $accounting.'journal_entries', $accounting.'journal_lines', $accounting.'warehouses', $accounting.'account_mappings'], true);
    }

    /**
     * @return list<int>
     */
    private function documentJournalIds(): array
    {
        $entries = config('accounting.table_prefix').'journal_entries';

        if (! Schema::hasTable($entries) || ! Schema::hasColumn($entries, 'source_type')) {
            return [];
        }

        return DB::table($entries)->whereIn('source_type', self::DOCUMENT_JOURNAL_SOURCES)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteJournals(array $ids): void
    {
        $prefix = config('accounting.table_prefix');

        foreach (array_chunk($ids, 1000) as $chunk) {
            DB::table($prefix.'journal_lines')->whereIn('journal_entry_id', $chunk)->delete();
            DB::table($prefix.'journal_entries')->whereIn('id', $chunk)->delete();
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function truncate(array $tables): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE TABLE '.implode(', ', array_map(fn (string $table): string => '"'.$table.'"', $tables)).' RESTART IDENTITY');

            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}

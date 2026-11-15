<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\StockTransfer;
use Modules\Accounting\Entities\StockTransferItem;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\Concerns\PostsInventoryJournal;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Entities\Product;

/**
 * التحويل المخزني: يخرج الصنف من مخزن/موقع بطبقات تكلفته ويدخل الآخر بنفس الطبقات.
 * بين مخزنين يُقيَّد من حساب مخزون المستلم الى حساب مخزون المرسل؛ داخل نفس المخزن
 * (نقل بين المواقع) لا قيد لان القيمة لم تتغير.
 */
class StockTransferService
{
    use PostsInventoryJournal;

    private const RELATIONS = ['fromWarehouse', 'toWarehouse', 'items.product', 'items.fromLocation', 'items.toLocation', 'journalEntry'];

    public function create(array $data): StockTransfer
    {
        return DB::transaction(function () use ($data) {
            $header = $this->normalizeHeader($data);
            $items = $this->normalizeItems($data['items'] ?? [], $header);
            $header['reference_no'] = app(ReferenceNumberService::class)->generate('stock_transfer', $header['transfer_date'], ['prefix' => 'TRF']);
            $header['status'] = InventoryDocumentStatus::DRAFT->value();
            $header['created_by'] = $data['created_by'] ?? auth()->id();

            $transfer = StockTransfer::create(array_merge($header, $this->totals($items)));
            $this->syncItems($transfer, $items);

            return $transfer->refresh()->load(self::RELATIONS);
        });
    }

    public function update(StockTransfer $transfer, array $data): StockTransfer
    {
        if (! $transfer->canBeEdited()) {
            throw new DomainException(__('Only draft documents can be updated.'));
        }

        return DB::transaction(function () use ($transfer, $data) {
            $header = $this->normalizeHeader($data);
            $items = $this->normalizeItems($data['items'] ?? [], $header);
            $transfer->update(array_merge($header, $this->totals($items)));
            $this->syncItems($transfer, $items);

            return $transfer->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(StockTransfer $transfer): void
    {
        if (! $transfer->canBeEdited()) {
            throw new DomainException(__('Only draft documents can be deleted.'));
        }

        DB::transaction(function () use ($transfer) {
            $transfer->items()->delete();
            $transfer->delete();
        });
    }

    public function post(StockTransfer $transfer): StockTransfer
    {
        // الصنف يخرج من مخزن ويدخل آخر: رصيده الكلي لا يتغير، فتُقيَّم تنبيهاته مرة واحدة في النهاية.
        return DB::transaction(fn () => app(StockAlertService::class)->batch(function () use ($transfer) {
            $transfer = StockTransfer::query()->with(['items', 'fromWarehouse', 'toWarehouse'])->lockForUpdate()->findOrFail($transfer->id);

            if (! $transfer->isDraft()) {
                throw new DomainException(__('Only draft documents can be posted.'));
            }

            if ($transfer->items->isEmpty()) {
                throw new DomainException(__('The document must have at least one item.'));
            }

            app(StockLevelService::class)->assertAvailable($transfer->from_warehouse_id, $transfer->items->map(fn (StockTransferItem $item) => [
                'product_id' => $item->product_id,
                'location_id' => $item->from_location_id,
                'quantity' => (float) $item->quantity,
            ])->all());

            $ledger = app(StockLedgerService::class);
            $layout = app(WarehouseLayoutService::class);
            $date = $transfer->transfer_date->toDateString();
            $total = 0.0;

            foreach ($transfer->items as $item) {
                $common = [
                    'product_id' => $item->product_id,
                    'quantity' => (float) $item->quantity,
                    'date' => $date,
                    'source' => $transfer,
                    'source_line_id' => $item->id,
                    'reference_no' => $transfer->reference_no,
                    'description' => __('Stock Transfer').' '.$transfer->reference_no,
                ];

                $movements = $ledger->issue(array_merge($common, [
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'location_id' => $item->from_location_id,
                    'weight' => (float) $item->weight > 0 ? (float) $item->weight : null,
                    'type' => StockMovementType::TRANSFER_OUT,
                ]));

                $cost = round((float) $movements->sum(fn (StockMovement $movement) => -(float) $movement->total_cost), 4);
                $weight = round((float) $movements->sum(fn (StockMovement $movement) => -(float) $movement->weight), 4);
                $unitCost = round($cost / (float) $item->quantity, 6);

                $ledger->receive(array_merge($common, [
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'location_id' => $item->to_location_id ?: $layout->primaryLocationId($item->product_id, $transfer->to_warehouse_id),
                    'weight' => $weight,
                    'unit_cost' => $unitCost,
                    // النقل يحمل طبقات الخروج بسعرها وترتيبها فلا تتغير القيمة الكلية.
                    'total_cost' => $cost,
                    'layers_from' => $movements,
                    'type' => StockMovementType::TRANSFER_IN,
                ]));

                if ($item->to_location_id) {
                    $layout->rememberLocation($item->product_id, $transfer->to_warehouse_id, (int) $item->to_location_id);
                }

                $item->update(['unit_cost' => $unitCost, 'total_cost' => $cost, 'weight' => $weight]);
                $total += $cost;
            }

            $entry = null;
            $total = round($total, 4);

            if ($transfer->from_warehouse_id !== $transfer->to_warehouse_id) {
                $accounts = app(InventoryAccounts::class);
                $fromAccount = $accounts->warehouseAccountId($transfer->fromWarehouse);
                $toAccount = $accounts->warehouseAccountId($transfer->toWarehouse);

                if ($fromAccount !== $toAccount) {
                    $entry = $this->postInventoryJournal($transfer, 'StockTransfer', $date, __('Stock Transfer').' '.$transfer->reference_no, [
                        ['account_id' => $toAccount, 'debit' => $total, 'credit' => 0],
                        ['account_id' => $fromAccount, 'debit' => 0, 'credit' => $total],
                    ]);
                }
            }

            $transfer->update([
                'status' => InventoryDocumentStatus::POSTED->value(),
                'total_cost' => $total,
                'total_weight' => round((float) $transfer->items()->sum('weight'), 4),
                'journal_entry_id' => $entry?->id,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            return $transfer->refresh()->load(self::RELATIONS);
        }));
    }

    public function cancel(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = StockTransfer::query()->with('journalEntry')->lockForUpdate()->findOrFail($transfer->id);

            if (! $transfer->isPosted()) {
                throw new DomainException(__('Only posted documents can be cancelled.'));
            }

            app(StockLedgerService::class)->reverse($transfer, now()->toDateString());
            $this->voidInventoryJournal($transfer, __('Cancellation of').' '.$transfer->reference_no);

            $transfer->update([
                'status' => InventoryDocumentStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ]);

            return $transfer->refresh()->load(self::RELATIONS);
        });
    }

    private function normalizeHeader(array $data): array
    {
        $from = (int) ($data['from_warehouse_id'] ?? 0);
        $to = (int) ($data['to_warehouse_id'] ?? 0);

        if (! $from || ! $to) {
            throw new DomainException(__('Please select the source and destination warehouses.'));
        }

        return [
            'from_warehouse_id' => $from,
            'to_warehouse_id' => $to,
            'branch_id' => $data['branch_id'] ?? Warehouse::query()->whereKey($from)->value('branch_id'),
            'transfer_date' => $data['transfer_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function normalizeItems(array $items, array $header): array
    {
        $normalized = [];
        $products = Product::query()->with('translations')->whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');

        foreach (array_values($items) as $index => $item) {
            $quantity = $this->decimal($item['quantity'] ?? 0);
            $product = $products->get((int) ($item['product_id'] ?? 0));

            if (! $product || $quantity <= 0) {
                continue;
            }

            if ($product->isService()) {
                throw new DomainException(__(':item is a service and has no stock.', ['item' => $product->name]));
            }

            $fromLocation = filled($item['from_location_id'] ?? null) ? (int) $item['from_location_id'] : null;
            $toLocation = filled($item['to_location_id'] ?? null) ? (int) $item['to_location_id'] : null;

            if ($header['from_warehouse_id'] === $header['to_warehouse_id'] && ($toLocation === null || $fromLocation === $toLocation)) {
                throw new DomainException(__('Within the same warehouse, choose a different destination location for :item.', ['item' => $product->name]));
            }

            $normalized[] = [
                'id' => $item['id'] ?? null,
                'product_id' => $product->id,
                'from_location_id' => $fromLocation,
                'to_location_id' => $toLocation,
                'barcode' => $product->barcode,
                'description' => $product->name,
                'quantity' => $quantity,
                'piece_length' => (float) $product->length,
                'weight' => $this->stockWeight($product, $quantity),
                'unit_cost' => app(StockLedgerService::class)->averageCost($product->id),
                'total_cost' => 0,
                'notes' => $item['notes'] ?? null,
                'sort_order' => $index,
                'created_by' => auth()->id(),
            ];
        }

        if ($normalized === []) {
            throw new DomainException(__('The document must have at least one item.'));
        }

        return $normalized;
    }

    private function totals(array $items): array
    {
        return [
            'total_quantity' => round(array_sum(array_column($items, 'quantity')), 4),
            'total_weight' => round(array_sum(array_column($items, 'weight')), 4),
            'total_cost' => round(array_sum(array_map(fn (array $item) => $item['quantity'] * $item['unit_cost'], $items)), 4),
        ];
    }

    private function syncItems(StockTransfer $transfer, array $items): void
    {
        $transfer->items()->delete();

        foreach ($items as $item) {
            unset($item['id']);
            $transfer->items()->create($item);
        }
    }
}

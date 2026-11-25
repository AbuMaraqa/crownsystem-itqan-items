<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesInvoiceItem;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Entities\SalesReturnItem;
use Modules\Accounting\Entities\SalesReturnItemPiece;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Enums\SalesReturnStatus;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\Concerns\PostsInventoryJournal;
use Modules\Accounting\Support\ReturnPieces;
use Modules\Accounting\Support\StockKey;
use Modules\Accounting\Support\WeightPieces;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;

/**
 * مرتجع المبيعات: يُرجع الزبون جزءاً من فاتورة بيع مرحّلة.
 *
 * - الزبون والعملة وسعر الصرف والضريبة والاسعار من الفاتورة نفسها؛ وكل سطر يشير الى سطر
 *   الفاتورة ولا تتجاوز كميته (المفوتر − المرتجع في مرتجعات مرحّلة اخرى).
 * - صنف الوزن يُرجع اصنافاً فرعية بعدد قطع (ReturnPieces).
 * - الترحيل: قيد عكس الايراد (من حـ/ الايراد والضريبة الى حـ/ الزبون)، وتعود البضاعة الى
 *   المخزن بكلفة خروجها مع قيد مخزون (من حـ/ مخزون المستودع الى حـ/ تكلفة البضاعة المباعة).
 *   طلبية الشحن المباشر من المورد لم تخرج من مخزننا، فلا تعود اليه.
 * - الالغاء: عكس حركة المخزون وقيد المخزون وقيد الايراد.
 */
class SalesReturnService
{
    use PostsInventoryJournal;

    private const SOURCE_MODULE = 'Accounting';

    private const EPSILON = 0.00005;

    private const RELATIONS = ['salesInvoice', 'client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces'];

    public function create(array $data): SalesReturn
    {
        return DB::transaction(function () use ($data) {
            $invoice = $this->returnableInvoice($data['sales_invoice_id'] ?? null);
            $header = $this->normalizeHeader($data, $invoice);
            $itemsInput = collect($data['items'] ?? [])->filter(fn ($item) => filled($item['sales_invoice_item_id'] ?? null))->all();
            $items = $this->normalizeItems($invoice, $itemsInput === [] ? $this->defaultItems($invoice) : $itemsInput, null);

            $header = array_merge($header, $this->calculateTotals($invoice, $items));
            $header['reference_no'] = app(ReferenceNumberService::class)->generate('sales_return', $header['return_date'], ['prefix' => 'SRET']);
            $header['status'] = SalesReturnStatus::DRAFT->value();
            $header['created_by'] = $data['created_by'] ?? auth()->id();

            $return = SalesReturn::create($header);
            $this->syncItems($return, $items);

            return $return->refresh()->load(self::RELATIONS);
        });
    }

    public function update(SalesReturn $return, array $data): SalesReturn
    {
        if (! $return->canBeEdited()) {
            throw new DomainException(__('Only draft sales returns can be updated.'));
        }

        return DB::transaction(function () use ($return, $data) {
            // فاتورة المرتجع لا تتغير بعد انشائه.
            $invoice = $this->returnableInvoice($return->sales_invoice_id);
            $header = $this->normalizeHeader($data, $invoice);
            $items = $this->normalizeItems($invoice, $data['items'] ?? [], $return->id);

            $return->update(array_merge($header, $this->calculateTotals($invoice, $items)));
            $this->syncItems($return, $items);

            return $return->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(SalesReturn $return): void
    {
        if (! $return->canBeEdited()) {
            throw new DomainException(__('Only draft sales returns can be deleted.'));
        }

        DB::transaction(function () use ($return) {
            $return->load('journalEntry.lines');

            if ($return->journalEntry) {
                $return->journalEntry->lines()->delete();
                $return->journalEntry->delete();
            }

            $return->items()->delete();
            $return->delete();
        });
    }

    public function post(SalesReturn $return): SalesReturn
    {
        if (! $return->canBePosted()) {
            throw new DomainException(__('Only draft sales returns can be posted.'));
        }

        return DB::transaction(function () use ($return) {
            $return = SalesReturn::query()
                ->with(['items.product', 'items.pieces', 'items.salesInvoiceItem', 'journalEntry'])
                ->lockForUpdate()
                ->findOrFail($return->id);

            // يُعاد الفحص بعد القفل حتى لا يُرحَّل المرتجع مرتين.
            if (! $return->canBePosted()) {
                throw new DomainException(__('Only draft sales returns can be posted.'));
            }

            if ($return->items->isEmpty() || (float) $return->total_amount <= 0) {
                throw new DomainException(__('Sales return must have at least one item before posting.'));
            }

            // قفل الفاتورة يمنع مرتجعين متزامنين من تجاوز الكمية المفوترة.
            $invoice = $this->returnableInvoice($return->sales_invoice_id, true);
            $this->assertWithinReturnable($invoice, $return);

            $journal = app(JournalEntryService::class);

            if ($return->journalEntry) {
                $journal->updateDraft($return->journalEntry, $this->journalData($return), $this->journalLines($return));
                $entry = $return->journalEntry->refresh();
            } else {
                $entry = $journal->createDraft($this->journalData($return), $this->journalLines($return));
                $return->update(['journal_entry_id' => $entry->id]);
            }

            $journal->post($entry);
            $this->receiveStock($return, $invoice);

            $return->update(['status' => SalesReturnStatus::POSTED->value()]);

            // المرتجع يسدد فاتورته اولاً (فتتفق اعمار الديون مع حساب العميل)؛ ما زاد يبقى
            // رصيداً للعميل يُخصَّص من لوحة التخصيص.
            $allocations = app(PaymentAllocationService::class);
            $amount = min((float) $return->total_amount, $allocations->outstanding($invoice->refresh()));

            if ($amount > self::EPSILON) {
                $allocations->allocate($return, $invoice, $amount, $return->return_date->toDateString());
            }

            return $return->refresh()->load([...self::RELATIONS, 'journalEntry']);
        });
    }

    /**
     * الغاء مرتجع مرحّل: تُفك تخصيصاته، وتخرج البضاعة المرتجعة من المخزن مجدداً، ويُعكس
     * قيدا المخزون والايراد.
     */
    public function cancel(SalesReturn $return, ?string $reason = null): SalesReturn
    {
        return DB::transaction(function () use ($return, $reason) {
            $return = SalesReturn::query()->with('journalEntry')->lockForUpdate()->findOrFail($return->id);

            if (! $return->isPosted()) {
                throw new DomainException(__('Only posted sales returns can be cancelled.'));
            }

            // تاريخ واحد لعكس الايراد والتكلفة والمخزون: تاريخ المستند ان كان شهره مفتوحاً، والا اليوم.
            $date = app(JournalEntryService::class)->postableDate($return->return_date->toDateString());
            $description = __('Cancellation of').' '.$return->reference_no;

            app(PaymentAllocationService::class)->deallocateAll($return);
            app(StockLedgerService::class)->reverse($return, $date);

            JournalEntry::query()
                ->where('sourceable_type', $return::class)
                ->where('sourceable_id', $return->id)
                ->where('source_module', InventoryAccounts::SOURCE_MODULE)
                ->where('status', JournalEntryStatus::POSTED->value())
                ->get()
                ->each(fn (JournalEntry $entry) => app(JournalEntryService::class)->void($entry, $description, $date));

            if ($return->journalEntry && $return->journalEntry->status === JournalEntryStatus::POSTED) {
                app(JournalEntryService::class)->void($return->journalEntry, $description, $date);
            }

            $return->update([
                'status' => SalesReturnStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => filled($reason) ? $reason : null,
            ]);

            return $return->refresh()->load([...self::RELATIONS, 'journalEntry']);
        });
    }

    /**
     * سطور الفاتورة مع المفوتر والمرتجع (في مرتجعات مرحّلة غير هذا) والقابل للارجاع.
     *
     * @return Collection<int, array{item: SalesInvoiceItem, invoiced: float, returned: float, returnable: float}>
     */
    public function returnableLines(SalesInvoice $invoice, ?int $exceptReturnId = null): Collection
    {
        $invoice->loadMissing(['items.product.translations', 'items.pieces']);
        $returned = $this->returnedQuantities($invoice, $exceptReturnId);

        return $invoice->items->mapWithKeys(fn (SalesInvoiceItem $item) => [$item->id => [
            'item' => $item,
            'invoiced' => (float) $item->quantity,
            'returned' => (float) ($returned[$item->id] ?? 0),
            'returnable' => max(round((float) $item->quantity - (float) ($returned[$item->id] ?? 0), 4), 0),
        ]]);
    }

    /**
     * صفوف جدول الاصناف لصفحة المرتجع: سطور الفاتورة القابلة للارجاع مع ما أُدخل فيه.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gridRows(SalesReturn $return): array
    {
        $invoice = $return->salesInvoice()->with(['items.product', 'items.pieces'])->firstOrFail();
        $return->loadMissing('items.pieces');

        return ReturnPieces::gridRows(
            $invoice->items,
            $return->items,
            $this->returnableLines($invoice, $return->id)->map(fn (array $line) => $line['returnable'])->all(),
            $this->returnedPieceCounts($invoice, $return->id),
            'sales_invoice_item_id',
            'sales_invoice_item_piece_id'
        );
    }

    /**
     * يحفظ ما أُدخل في المرتجع (ما دام مسودة وأُرسل) ثم ينفذ الاجراء عليه (الترحيل)، في معاملة
     * واحدة.
     *
     * @template T
     *
     * @param  callable(SalesReturn): T  $action
     * @return T
     */
    public function saveThen(SalesReturn $return, ?array $data, callable $action): mixed
    {
        return DB::transaction(function () use ($return, $data, $action) {
            if ($data !== null && $return->canBeEdited()) {
                $return = $this->update($return, $data);
            }

            return $action($return);
        });
    }

    /**
     * الفواتير التي يُرجع منها هذا الزبون: المرحّلة فقط.
     *
     * @return list<array{value: int, label: string}>
     */
    public function invoiceOptions(mixed $clientId, ?string $search = null): array
    {
        if (blank($clientId)) {
            return [];
        }

        return SalesInvoice::query()
            ->where('client_id', $clientId)
            ->where('status', SalesInvoiceStatus::POSTED->value())
            ->when(filled($search), fn ($query) => $query->where('reference_no', 'like', "%{$search}%"))
            ->latest('invoice_date')
            ->limit(50)
            ->get(['id', 'reference_no'])
            ->map(fn (SalesInvoice $invoice): array => ['value' => (int) $invoice->id, 'label' => (string) $invoice->reference_no])
            ->all();
    }

    /**
     * ما يعرضه نموذج المرتجع الجديد من الفاتورة المختارة: المخزن والعملة والضريبة وسعر الصرف.
     * الخدمة تأخذها من الفاتورة نفسها عند الحفظ.
     *
     * @return array<string, mixed>
     */
    public function invoiceDefaults(SalesInvoice $invoice): array
    {
        return [
            'sales_invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'branch_id' => $invoice->branch_id,
            'warehouse_id' => $invoice->warehouse_id ?: $invoice->salesOrder?->warehouse_id,
            'currency_id' => $invoice->currency_id,
            'tax_id' => $invoice->tax_id,
            'exchange_rate' => number_format((float) $invoice->exchange_rate ?: 1, 8, '.', ''),
            'invoice_date' => $invoice->invoice_date?->toDateString(),
        ];
    }

    private function returnableInvoice(mixed $invoiceId, bool $lock = false): SalesInvoice
    {
        $invoice = blank($invoiceId) ? null : SalesInvoice::query()
            ->with(['items.product.translations', 'items.pieces', 'salesOrder'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->find($invoiceId);

        if (! $invoice || ! $invoice->isPosted()) {
            throw new DomainException(__('Select a posted sales invoice to return from.'));
        }

        return $invoice;
    }

    private function normalizeHeader(array $data, SalesInvoice $invoice): array
    {
        $returnDate = $data['return_date'] ?? now()->toDateString();

        if ($returnDate < $invoice->invoice_date->toDateString()) {
            throw new DomainException(__('The return date cannot be before the invoice date.'));
        }

        return [
            'sales_invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'branch_id' => $invoice->branch_id,
            'warehouse_id' => filled($data['warehouse_id'] ?? null)
                ? (int) $data['warehouse_id']
                : ($invoice->warehouse_id ?: $invoice->salesOrder?->warehouse_id),
            'return_date' => $returnDate,
            'currency_id' => $invoice->currency_id,
            'tax_id' => $invoice->tax_id,
            'exchange_rate' => (float) $invoice->exchange_rate ?: 1,
            'tax_rate' => (float) $invoice->tax_rate,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * كل ما بقي قابلاً للارجاع من الفاتورة (عند انشاء المرتجع بلا سطور).
     */
    private function defaultItems(SalesInvoice $invoice): array
    {
        $returnedPieces = $this->returnedPieceCounts($invoice, null);

        return $this->returnableLines($invoice)
            ->filter(fn (array $line) => $line['returnable'] > 0)
            ->map(fn (array $line) => [
                'sales_invoice_item_id' => $line['item']->id,
                'quantity' => $line['returnable'],
                'pieces' => ReturnPieces::allAvailable($line['item']->pieces, $returnedPieces, 'sales_invoice_item_piece_id'),
            ])
            ->values()
            ->all();
    }

    /**
     * السطر يُسعَّر من سطر الفاتورة (السعر وحصة الخصم بنسبة الكمية)، ولا يُقبل سطر خارجها.
     */
    private function normalizeItems(SalesInvoice $invoice, array $items, ?int $exceptReturnId): array
    {
        $plan = $this->returnableLines($invoice, $exceptReturnId);
        $returnedPieces = $this->returnedPieceCounts($invoice, $exceptReturnId);
        $normalized = [];

        foreach (array_values($items) as $item) {
            if (blank($item['sales_invoice_item_id'] ?? null)) {
                continue;
            }

            $line = $plan->get((int) $item['sales_invoice_item_id']);

            if (! $line) {
                throw new DomainException(__('The item does not belong to the sales invoice.'));
            }

            /** @var SalesInvoiceItem $source */
            $source = $line['item'];
            $quantity = $this->decimalValue($item['quantity'] ?? 0);
            $pieces = [];

            // صنف وزن باصنافه الفرعية: يُرجع بعدد قطع كل صنف فرعي، والكمية (كغم) منها.
            if ($source->pieces->isNotEmpty()) {
                $pieces = ReturnPieces::normalize($source->pieces, $returnedPieces, $item['pieces'] ?? [], 'sales_invoice_item_piece_id', $source->description ?: (string) $source->product?->name);
                $quantity = $pieces === [] ? 0.0 : ReturnPieces::quantity($source, $pieces);
            }

            if ($quantity <= 0 && $pieces === []) {
                continue;
            }

            $this->assertQuantity($source, $quantity, $line['returnable']);

            $gross = round($quantity * (float) $source->unit_price, 4);
            $discount = (float) $source->quantity > 0
                ? min(round((float) $source->discount_amount * $quantity / (float) $source->quantity, 4), $gross)
                : 0.0;

            $normalized[] = [
                'id' => $item['id'] ?? null,
                'sales_invoice_item_id' => $source->id,
                'product_id' => $source->product_id,
                'category_finish_id' => $source->category_finish_id,
                'line_type' => $source->line_type ?: ($source->product?->isService() ? ItemType::SERVICE->value : ItemType::GOODS->value),
                'barcode' => $source->barcode,
                'description' => filled($item['description'] ?? null) ? $item['description'] : $source->description,
                'quantity' => $quantity,
                'total_weight' => round(array_sum(array_column($pieces, 'total_weight')), 4),
                'unit_price' => (float) $source->unit_price,
                'discount_rate' => (float) $source->discount_rate,
                'discount_amount' => $discount,
                'line_total' => max(round($gross - $discount, 4), 0),
                'notes' => $item['notes'] ?? null,
                'created_by' => $item['created_by'] ?? auth()->id(),
                'pieces' => $pieces,
            ];
        }

        if ($normalized === []) {
            throw new DomainException(__('Enter the returned quantity for at least one item.'));
        }

        return $normalized;
    }

    private function assertWithinReturnable(SalesInvoice $invoice, SalesReturn $return): void
    {
        $plan = $this->returnableLines($invoice, $return->id);
        $returnedPieces = $this->returnedPieceCounts($invoice, $return->id);

        foreach ($return->items as $item) {
            $line = $plan->get((int) $item->sales_invoice_item_id);

            if (! $line) {
                throw new DomainException(__('The item does not belong to the sales invoice.'));
            }

            $this->assertQuantity($line['item'], (float) $item->quantity, $line['returnable']);

            if ($item->pieces->isNotEmpty()) {
                ReturnPieces::normalize($line['item']->pieces, $returnedPieces, $item->pieces->toArray(), 'sales_invoice_item_piece_id', $item->description ?: (string) $item->product?->name);
            }
        }
    }

    private function assertQuantity(SalesInvoiceItem $item, float $quantity, float $returnable): void
    {
        if ($quantity > $returnable + self::EPSILON) {
            throw new DomainException(__(':item: returned quantity (:quantity) exceeds the returnable quantity (:remaining).', [
                'item' => $item->description ?: $item->product?->name,
                'quantity' => $quantity + 0,
                'remaining' => $returnable + 0,
            ]));
        }
    }

    /**
     * @return array<int, float> sales_invoice_item_id => الكمية المرتجعة في مرتجعات مرحّلة
     */
    private function returnedQuantities(SalesInvoice $invoice, ?int $exceptReturnId): array
    {
        $returns = (new SalesReturn)->getTable();

        return SalesReturnItem::query()
            ->join($returns.' as returns', 'returns.id', '=', 'sales_return_id')
            ->where('returns.sales_invoice_id', $invoice->id)
            ->where('returns.status', SalesReturnStatus::POSTED->value())
            ->whereNull('returns.deleted_at')
            ->when($exceptReturnId, fn ($query) => $query->where('returns.id', '!=', $exceptReturnId))
            ->selectRaw('sales_invoice_item_id, SUM(quantity) as quantity')
            ->groupBy('sales_invoice_item_id')
            ->pluck('quantity', 'sales_invoice_item_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();
    }

    /**
     * @return array<int, float> sales_invoice_item_piece_id => القطع المرتجعة في مرتجعات مرحّلة
     */
    private function returnedPieceCounts(SalesInvoice $invoice, ?int $exceptReturnId): array
    {
        $returns = (new SalesReturn)->getTable();
        $items = (new SalesReturnItem)->getTable();

        return SalesReturnItemPiece::query()
            ->join($items.' as return_items', 'return_items.id', '=', 'sales_return_item_id')
            ->join($returns.' as returns', 'returns.id', '=', 'return_items.sales_return_id')
            ->where('returns.sales_invoice_id', $invoice->id)
            ->where('returns.status', SalesReturnStatus::POSTED->value())
            ->whereNull('returns.deleted_at')
            ->whereNull('return_items.deleted_at')
            ->when($exceptReturnId, fn ($query) => $query->where('returns.id', '!=', $exceptReturnId))
            ->selectRaw('sales_invoice_item_piece_id, SUM(piece_count) as piece_count')
            ->groupBy('sales_invoice_item_piece_id')
            ->pluck('piece_count', 'sales_invoice_item_piece_id')
            ->map(fn ($count) => (float) $count)
            ->all();
    }

    /**
     * خصم الفاتورة العام يوزَّع على المرتجع بنسبة صافي سطوره الى صافي سطور الفاتورة.
     */
    private function calculateTotals(SalesInvoice $invoice, array $items): array
    {
        $subTotal = round(collect($items)->sum(fn (array $item) => round($item['quantity'] * $item['unit_price'], 4)), 4);
        $itemsDiscount = round(collect($items)->sum('discount_amount'), 4);
        $net = max(round($subTotal - $itemsDiscount, 4), 0);
        $invoiceNet = round((float) $invoice->sub_total - (float) $invoice->items_discount, 4);
        $discount = $invoiceNet > 0 ? min(round((float) $invoice->invoice_discount * $net / $invoiceNet, 4), $net) : 0.0;
        $taxable = max(round($net - $discount, 4), 0);
        $taxAmount = round($taxable * ((float) $invoice->tax_rate / 100), 4);

        return [
            'sub_total' => $subTotal,
            'items_discount' => $itemsDiscount,
            'invoice_discount' => $discount,
            'tax_amount' => $taxAmount,
            'total_amount' => round($taxable + $taxAmount, 4),
        ];
    }

    private function syncItems(SalesReturn $return, array $items): void
    {
        $keep = collect($items)->pluck('id')->filter()->values();
        $return->items()->when($keep->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $keep))->delete();

        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $pieces = $item['pieces'] ?? [];
            unset($item['id'], $item['pieces']);

            if ($id && $existing = $return->items()->whereKey($id)->first()) {
                $existing->update($item);
            } else {
                $existing = $return->items()->create($item);
            }

            WeightPieces::sync($existing->pieces(), $pieces);
        }
    }

    /**
     * تعود البضاعة المرتجعة الى المخزن بكلفة خروجها مع الفاتورة (والا بمتوسط الكلفة الحالي)،
     * وتُعكس تكلفة البضاعة المباعة بقيد مخزون. صنف الوزن يعود كغمه الى صنفه الرئيسي بكلفة خروجه،
     * وقطعه بعددها بلا قيمة.
     */
    private function receiveStock(SalesReturn $return, SalesInvoice $invoice): void
    {
        if ($invoice->salesOrder && app(DeliveryNoteService::class)->isDirectShipment($invoice->salesOrder)) {
            return;
        }

        $lines = [];

        foreach ($return->items as $item) {
            // سطر مجموعة السعر، كما في اتقان: الكيلو يعود الى المجموعة بكلفة خروجه، وكل لون بعدد قطعه بلا قيمة.
            if ($item->isPriceGroupLine()) {
                if ((float) $item->quantity > 0) {
                    $lines[] = ['item' => $item, 'piece' => null, 'key' => StockKey::group((int) $item->category_finish_id), 'quantity' => (float) $item->quantity, 'weight' => (float) $item->quantity];
                }

                foreach ($item->pieces as $piece) {
                    if ($piece->product_id && (float) $piece->piece_count > 0) {
                        $lines[] = ['item' => $item, 'piece' => $piece, 'key' => StockKey::product((int) $piece->product_id), 'quantity' => (float) $piece->piece_count, 'weight' => (float) $piece->total_weight];
                    }
                }

                continue;
            }

            $product = $item->product;

            if (! $product || $product->isService() || $item->line_type === ItemType::SERVICE->value) {
                continue;
            }

            if ($item->pieces->isNotEmpty()) {
                if ((float) $item->quantity > 0) {
                    $lines[] = ['item' => $item, 'piece' => null, 'key' => StockKey::product((int) $product->id), 'quantity' => (float) $item->quantity, 'weight' => (float) $item->quantity];
                }

                foreach ($item->pieces as $piece) {
                    $lines[] = ['item' => $item, 'piece' => $piece, 'key' => StockKey::product((int) $piece->product_id), 'quantity' => (float) $piece->piece_count, 'weight' => (float) $piece->total_weight];
                }

                continue;
            }

            [$quantity, $weight] = $this->legacyStockQuantity($product, (float) $item->quantity);
            $lines[] = ['item' => $item, 'piece' => null, 'key' => StockKey::product((int) $product->id), 'quantity' => $quantity, 'weight' => $weight];
        }

        if ($lines === []) {
            return;
        }

        $warehouseId = $return->warehouse_id
            ?: throw new DomainException(__('Select the warehouse that receives the returned goods.'));
        $ledger = app(StockLedgerService::class);
        $layout = app(WarehouseLayoutService::class);
        $issuedCosts = $this->issuedUnitCosts($invoice);
        $date = $return->return_date->toDateString();
        $itemCosts = [];

        foreach ($lines as $line) {
            $key = $line['key'];
            $unitCost = $issuedCosts[$key->column.':'.$key->id] ?? ($key->isGroup() ? $ledger->groupAverageCost($key->id) : $ledger->averageCost($key->id));

            $movement = $ledger->receive([
                ...$key->attributes(),
                'warehouse_id' => (int) $warehouseId,
                'location_id' => $key->isGroup() ? null : $layout->primaryLocationId($key->id, (int) $warehouseId),
                'quantity' => round($line['quantity'], 4),
                'weight' => round($line['weight'], 4),
                'unit_cost' => $unitCost,
                'type' => StockMovementType::SALES_RETURN,
                'date' => $date,
                'source' => $return,
                'source_line_id' => $line['item']->id,
                'reference_no' => $return->reference_no,
                'description' => __('Sales return').' '.$return->reference_no,
            ]);

            $line['piece']?->update(['unit_cost' => round((float) $movement->unit_cost, 6)]);
            $itemCosts[$line['item']->id] = round(($itemCosts[$line['item']->id] ?? 0) + (float) $movement->total_cost, 4);
        }

        foreach ($return->items as $item) {
            if (isset($itemCosts[$item->id]) && (float) $item->quantity > 0) {
                $item->update(['unit_cost' => round($itemCosts[$item->id] / (float) $item->quantity, 6)]);
            }
        }

        $total = round(array_sum($itemCosts), 4);
        $accounts = app(InventoryAccounts::class);

        $this->postInventoryJournal($return, 'SalesReturn', $date, __('Cost of Goods Sold').' - '.__('Sales return').' '.$return->reference_no, [
            ['account_id' => $accounts->warehouseAccountId((int) $warehouseId), 'debit' => $total, 'credit' => 0],
            ['account_id' => $accounts->mapped(InventoryAccounts::COGS_KEY), 'debit' => 0, 'credit' => $total],
        ]);
    }

    /**
     * كلفة الوحدة التي خرج بها كل صنف مع الفاتورة: حركات الفاتورة نفسها (بلا طلبية) او سندات
     * تسليم طلبيتها.
     *
     * @return array<int, float> product_id => كلفة الوحدة بالعملة الاساسية
     */
    private function issuedUnitCosts(SalesInvoice $invoice): array
    {
        $noteIds = DeliveryNote::query()
            ->where(fn ($query) => $query
                ->where('sales_invoice_id', $invoice->id)
                ->when($invoice->sales_order_id, fn ($query) => $query->orWhere('sales_order_id', $invoice->sales_order_id)))
            ->pluck('id');

        return StockMovement::query()
            ->whereIn('type', [StockMovementType::DELIVERY->value, StockMovementType::INVOICE_ADJUSTMENT->value])
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('sourceable_type', $invoice->getMorphClass())->where('sourceable_id', $invoice->id))
                ->orWhere(fn ($query) => $query->where('sourceable_type', (new DeliveryNote)->getMorphClass())->whereIn('sourceable_id', $noteIds)))
            ->selectRaw('product_id, category_finish_id, SUM(total_cost) as cost, SUM(quantity) as quantity')
            ->groupBy('product_id', 'category_finish_id')
            ->get()
            ->filter(fn ($row) => (float) $row->quantity != 0.0)
            ->mapWithKeys(fn ($row) => [StockKey::from($row->toArray())->column.':'.StockKey::from($row->toArray())->id => round((float) $row->cost / (float) $row->quantity, 6)])
            ->all();
    }

    /**
     * سطر قديم بلا اصناف فرعية: صنف الكمية بكميته، وصنف الوزن (كغم) بعدد قطع = الوزن ÷ (الطول × وزن المتر).
     *
     * @return array{0: float, 1: float}
     */
    private function legacyStockQuantity(Product $product, float $quantity): array
    {
        if ($product->pricing_type !== 'weight') {
            return [round($quantity, 4), 0.0];
        }

        if (app(StockLedgerService::class)->storesByWeight($product)) {
            return [round($quantity, 4), round($quantity, 4)];
        }

        $perPiece = round((float) $product->length * (float) $product->weight_per_meter, 4);

        if ($perPiece <= 0) {
            throw new DomainException(__(':item is priced by weight; set its piece length and weight per meter so the weight can be converted to pieces.', [
                'item' => $product->name,
            ]));
        }

        return [round($quantity / $perPiece, 4), round($quantity, 4)];
    }

    private function journalData(SalesReturn $return): array
    {
        return [
            'entry_date' => $return->return_date?->toDateString(),
            'reference_no' => $return->reference_no,
            'source_module' => self::SOURCE_MODULE,
            'source_type' => 'SalesReturn',
            'sourceable_id' => $return->id,
            'sourceable_type' => $return::class,
            'description' => __('Sales return').' '.$return->reference_no,
            'created_by' => $return->created_by ?? auth()->id(),
        ];
    }

    /**
     * عكس قيد الفاتورة للبنود المرتجعة: من حـ/ ايراد كل نوع ومن حـ/ الضريبة
     * الى حـ/ العميل (حسابه الفرعي). تكلفة الخدمة لا تُعكس، فقد قُدّمت فعلاً.
     */
    private function journalLines(SalesReturn $return): array
    {
        $ledger = app(SalesLedgerAccounts::class);
        $return->loadMissing('items.product');
        $reference = $return->reference_no;
        $lines = [];

        $revenueLines = $return->items->map(fn ($item): array => [
            'account_id' => $ledger->revenueAccountId($item->product, $item->line_type === ItemType::SERVICE->value),
            'line_total' => (float) $item->line_total,
        ]);
        $netRevenue = round((float) $return->total_amount - (float) $return->tax_amount, 4);

        foreach ($ledger->allocateRevenue($revenueLines, $netRevenue) as $accountId => $amount) {
            $lines[] = $this->journalLine($return, $accountId, $amount, 0, __('Sales return').' '.$reference);
        }

        if ((float) $return->tax_amount > 0) {
            $lines[] = $this->journalLine($return, $ledger->mapped(SalesLedgerAccounts::TAX_KEY), (float) $return->tax_amount, 0, __('Sales return tax').' '.$reference);
        }

        $lines[] = $this->journalLine($return, $ledger->customerAccountId($return->client_id), 0, (float) $return->total_amount, __('Sales return receivable').' '.$reference);

        return $lines;
    }

    private function journalLine(SalesReturn $return, int $accountId, float $debit, float $credit, string $description): array
    {
        return [
            'account_id' => $accountId,
            'branch_id' => $return->branch_id,
            'currency_id' => $return->currency_id,
            'exchange_rate' => $return->exchange_rate,
            'debit' => round($debit, 4),
            'credit' => round($credit, 4),
            'description' => $description,
            'created_by' => $return->created_by ?? auth()->id(),
        ];
    }

    private function decimalValue(mixed $value, float $default = 0, int $scale = 4): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            throw new DomainException(__('Invalid numeric value.'));
        }

        return round((float) $value, $scale);
    }
}

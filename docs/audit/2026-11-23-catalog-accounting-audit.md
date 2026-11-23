# Audit 2026-11-23 — catalogue phase 2, accounting, consolidated purchase orders, production

Four read-only reviews (price-group lines, core accounting, consolidated purchase orders, production). Each line:
severity · finding · where · status. Status: **open**, **fixed (commit)**, **ruled** (decision recorded).

## Price-group lines (phase 2)
- critical · Sales return of a group line credits the customer, no stock back, no COGS reversal · `SalesReturnService` normalizeItems/receiveStock · fixed (67d4c5c)
- critical · Purchase return of a group line leaves stock, credits Purchases/5115 · `PurchaseReturnService` · fixed (67d4c5c)
- important · Direct-supply sales order drops group lines from the direct PO draft · `Support/DirectPurchaseOrderDraft.php:37-60` · fixed (3b62263)
- important · Group line with actual weight but no/weightless pieces is billed with no stock (edited lines skip the check); `actualShares` zeroes value when calculated weight is 0 · `ItemsGrid::ensureGroupLinesHaveTheirColours`, `WeightPieces::actualShares` · fixed (3b62263; weightless pieces now refused on every group line, so actualShares never sees 0)
- minor · Partial receipts/deliveries round weight/value per unit (16.2501 kg, 99.9999) · `GoodsReceiptService::outstandingLines`, `DeliveryNoteService` · open
- minor · Group revaluation booked on the last receipt's warehouse only · `InvoiceStockService::priceGroupRevaluations` · open
- minor · Last prices never recorded for groups · `CustomerHistoryService:633` · open
- guard · Reject `isPiece` products (with `main_product_id`) as group pieces · `ItemsGrid` · ruled: already refused by the foreign-colour check (a legacy piece has no category_finish_id)

## Consolidated purchase orders
- critical · Group lines cannot be consolidated (no column, product_id required, treated as quantity) · `PurchaseOrderConsumption`, `PurchaseOrderMainItems`, `ConsolidatedPurchaseOrderService::normalizeItems`, request rule · fixed (763cb78)
- important · Two CPOs can take the same pieces (no locked re-check at save; client pricing_type trusted) · `ConsolidatedPurchaseOrderController::store`, `PurchaseOrderConsumption::assertRowQuantities` · fixed (6f7d80f)
- important · Reactivating a cancelled CPO double-counts; cancelled/ready CPOs still editable · `ConsolidatedPurchaseOrderService::changeStatus` · fixed for reactivation (6f7d80f); editing cancelled/ready orders still open
- important · Source PO lines can be deleted/reduced after consolidation · `PurchaseOrderService` delete/syncItems · open
- important · Partly pulled weight lines valued 0 (actual_weight nulled) · `PurchaseOrderConsumption::withPieces:114` · ruled: kept — the system-wide rule is "a weight line is priced only by its actual weight"; a partial pull has no actual weight until entered (tests encode it). Owner to decide if partial pulls should take a weight share.
- important · Slow: fully-consumed scan per keystroke, remainingFor per row, header save rewrites all items · open
- minor · created_by overwritten; supplier/warehouse columns never filled; orphan links on item delete; storeParty/storeLookup bypass permissions; picker UX; PDF without pieces; label mismatch · open

## Production
- critical · Weight materials costed as weight × cost-per-piece (×9) for non-main items/variations · `ProductionOrderService:228,334,523`, `ProductionCost` · fixed (3b62263, `StockLedgerService::costPerKg`)
- important · Group lines/variations lost or cost 0 when filled from a sales order; variations not searchable · `linesFromSalesOrder`, `ProductSearch::rank` · fixed for sales-order group lines (3b62263); variations in the materials search still open
- important · Legacy pieces cost 0 silently · fixed (3b62263, costPerKg uses the main item)
- important · Currency mix (base-currency costs shown as order currency) · open
- important · Margin uses the whole sales order for several production orders · open
- design · Production posts no stock and no journal (INV-14/D-08) — label as costing estimate until built · ruled pending owner
- minor/UX · update without lock; reopen uses Complete permission; worker list includes clients; edit link for view-only users; client notified of internal doc · open
- removal candidates · `ItemsGridDocuments` 'production-order' entry; unused `notes` columns; `ProductionOrderMaterial::salesOrderItem()`; `createFromSalesOrder` (tests only) · awaiting owner

## Core accounting
- critical · Income statement double-counts a year after cancelling its closing voucher · `AccountingReportService:427-429` · fixed (4cb46d8)
- critical · Delivery/shipping/insurance/clearance companies get accounts under Receivables; one account per user regardless of role · `AccountService:32-59`, `PartyAccounts`, `AccountMappingSeeder:33` · fixed for new/unused accounts (883390d); existing provider accounts with entries on the server need a reviewed data fix (owner approval)
- important · Trial balance unbalanced while the previous year is open (no unclosed profit) · `AccountingReportService:441-454` · fixed (1ebb2b6)
- important · Foreign-currency allocations fail in a closed month · `PaymentAllocationService:63,320` · fixed (3fa59d9)
- important · Inactive account blocks cancellations and year closing · `JournalEntryService:129-147,341`, `PeriodClosingVoucherService:138-157` · fixed (b0d59b6)
- important · Parent-account mappings editable to leaves; seeder overwrites user mappings · `AccountMappingRequest`, `AccountMappingSeeder` · open
- important · `AccountBalance` written on every post, never read (locks, duplicates) · `JournalEntryService:370-396` · open (removal candidate)
- important · Cancellation splits revenue and cost reversals across dates · Sales/Purchase invoice & return cancel · fixed (3fa59d9)
- important · Unbounded queries (Account::options, aging, ledger $before, statement) · open
- minor · base currency with rate ≠ 1; default currency resolved two ways; purchase return rate shown vs saved; service unit_cost currency (suspected); API trial balance unvalidated; reopen year reopens closed months; delete leaves lines; voucher cash account may be the party · open
- removal candidates · AccountBalance + rebuild command; dead mapping keys (7 Items.*, purchase_invoice_payable, sales_invoice_receivable, RECEIVABLE_KEY); accountTypeSummary; JournalEntry total*Base/isBalanced (tests only); FiscalYear close/reopen wrappers; redundant reversal update · awaiting owner
- duplication · Receipt/Payment voucher services ~95% identical; `mapped()` ×3; `decimalValue` ×13; `toBase()` ×2; posted-lines query ×5; account label ×2 · open

## Fixed outside the audit
- Category edit page/save crashed for sub-categories (`orWhereKey` does not exist) · `CategoryRepository::parentTree`; same in `StockAdjustmentService:233` · fixed (1c18607)

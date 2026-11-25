# Catalog phases 3+4: remove the fake main item — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No fake main item anywhere: `main_product_id` and every code path built on it are gone; weight colour variations are ordinary stock items, and kg balances/prices live on the price group (category × colour).

**Architecture:** The ledger already stores a colour variation by pieces with its weight and value (`StockLedgerService::costPerKg` divides the piece cost by the piece weight). This plan deletes the two legacy special cases — the *piece* (zero-value sub-item whose value sits on its main item) and the *main item line* (a document line on the fake product with sub-items as pieces) — and replaces every main-item aggregate with a price-group aggregate over its variations. Document pieces tables stay: they hold the colour variations of a group line (phase 2, D2-3).

**Tech Stack:** Laravel 12, nwidart modules, Inertia 3 + Vue 3 + TS, PHPUnit 11 on MySQL (`phpunit.verify.xml`, DB `crownsystem_test_f942`), PostgreSQL in production.

**Spec:** `docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md` (D-1, D-7, phases 3-4) and `docs/superpowers/specs/2026-11-22-catalog-phase-2-document-lines-design.md` (D2-3, D2-7). Council ruling 2026-11-25: phases 3+4 together on an isolated branch, no compatibility layer (production holds test data only), one cutover.

## Global Constraints

- Work only in worktree `D:/projects/itqan-verify`, branch `catalog-v2`; never in the shared tree. Merge to `catalog-redesign` once, at the end.
- Never run two PHPUnit processes at once; test command: `vendor/bin/phpunit -c <scratchpad>/phpunit.verify.xml --no-coverage --filter "<Tests>"`.
- SQL that differs between engines goes through `SqlDialect`; code must run on PostgreSQL and MySQL.
- Every user-facing string: `__('English')` / `t('English')` with an MSA value in the module `Lang/ar.json`.
- Permissions: a changed or new route keeps `->can(...)`; filters/labels follow the `permissions` skill.
- Old documents with main-item lines are not migrated (D2-7 + council): production test documents are deleted at cutover (separate approval). The code need not read them.
- A weight product **without** a piece measure (length × weight per metre = 0) is stored by kg — this stays (`StockLedgerService::storesByWeight`).
- No `route:cache` / `optimize` on the server.

## Review Focus

1. A group line received in two partial goods receipts: the kg and value of each receipt must follow its own pieces, and the two receipts must sum to the invoice — test in Task 3.
2. A colour variation issued below zero with the negative-stock permission keeps weight and value consistent (negative weight with negative quantity) — test in Task 1.
3. A weight product with no piece measure (sold by kg) still receives/issues in kg with value — test in Task 1.
4. Stock balances page filtered by a price group lists its variations and a group total row (kg, pieces, value), and stays one query per page — test in Task 2.
5. A document line that carries pieces but no `category_finish_id` is refused by validation with a clear message (no silent main-item path) — test in Task 5.

---

### Task 0: Workspace and baseline

**Files:** none (tooling only)

- [ ] **Step 1:** Link the shared `node_modules` into the worktree (`cmd //c mklink /J D:\projects\itqan-verify\node_modules D:\projects\crownsystem-itqan-items\node_modules`) so `npm run typecheck` runs there.
- [ ] **Step 2:** Run the Accounting and Items suites on `catalog-v2` HEAD (`a3f8cb2`) and save the list of failing tests to the ledger as the baseline. Expected: green, or a named list that every later task must not grow.

### Task 1: Ledger without pieces (phase 3 core)

**Files:**
- Modify: `Modules/Accounting/Services/StockLedgerService.php` (remove `isPiece`, rename `isMainItem` → `storesByWeight`, simplify `costPerKg`, receive/issue/reverse branches)
- Modify: `Modules/Accounting/Services/Concerns/PostsInventoryJournal.php` (`storesByWeight`)
- Modify: `Modules/Items/Support/ProductStockSummary.php`, `Modules/Items/Entities/Product.php` (`lowStock` without main exclusion)
- Test: rewrite `Modules/Accounting/tests/Feature/WeightMainItemBalanceTest.php` → `WeightVariationStockTest.php`

**Interfaces — Produces:**
- `StockLedgerService::storesByWeight(Product $product): bool` — true when `pricing_type = weight` and `length × weight_per_meter <= 0`.
- `StockLedgerService::costPerKg(Product $product): float` — by-weight item: average cost; weight item with a piece measure: average ÷ (length × weight per metre); others: average.

- [ ] **Step 1: Write the failing tests** in `WeightVariationStockTest`:
  - `test_a_colour_variation_is_received_and_issued_with_its_own_value`: receive 10 pieces, weight 81.25, total 1625 → balance 10 / 81.25, average 162.5, `costPerKg` = 20.0; issue 4 → movement value −650, weight −32.5.
  - `test_a_variation_issued_below_zero_keeps_negative_weight_and_value` (with `StockLedgerService::NEGATIVE_STOCK_PERMISSION`): balance 2 pcs, issue 3 → quantity −1, weight −8.125.
  - `test_a_weight_item_without_a_piece_measure_is_stored_by_kg`: receive 50 kg at 4 → `storesByWeight` true, `costPerKg` 4.0.
- [ ] **Step 2:** Run — expected FAIL (`storesByWeight` undefined).
- [ ] **Step 3:** Implement; delete `isPiece` and its branches in `receive`, `issue`, `reverseReceive`, `reverseIssue`.
- [ ] **Step 4:** Run the three tests plus `StockCostLayersTest|InventoryFlowTest` — PASS except tests that build a piece with `main_product_id`; rewrite those fixtures to colour variations (`category_finish_id` set, no `main_product_id`).
- [ ] **Step 5:** Commit `feat(stock): colour variations carry their own value; no zero-value pieces`.

### Task 2: Price-group kg balance and the stock screens

**Files:**
- Modify: `Modules/Accounting/Services/StockLevelService.php` (`mainItemLevels`/`subItemLevels` → `groupLevels`), `StockBalancesService.php` (filter `price_group` replaces `main_product`), `StockAlertService.php`, `ItemStockCardService.php`, `StockFollowUpService.php`, `Support/ItemCardData.php`, `Support/InventoryLines.php`
- Modify controllers: `Http/Controllers/Admin/Inventory/{StockBalance,StockAdjustment,StockFollowUp,ItemStockCard,StockTransfer}Controller.php`, `routes/inventory.php` (`main-products` lookup → `price-groups`)
- Modify Vue: `Pages/Inventory/StockBalances/Index.vue`, `StockAdjustment/StockAdjustmentFields.vue`, `StockFollowUp/{Index,FollowUpDetails}.vue` + `types.ts`, `ItemCard/Index.vue`, `parts/item-card/types.ts`; `Modules/Items/resources/js/Pages/Product/parts/StockSummaryPanel.vue`
- Modify: `Services/InventoryPdfService.php`, `Http/Controllers/InventoryPrintController.php` (stock report grouped by price group)
- Test: `Modules/Accounting/tests/Feature/PriceGroupStockTest.php`

**Interfaces — Produces:**
- `StockLevelService::groupLevels(array $categoryFinishIds, ?int $warehouseId = null): array<int, array{pieces: float, weight: float, value: float}>` — sums of the variations' balances and values, one query.
- Stock balances filter key `price_group` (exact `category_finish_id`), and a group total row in the payload `group_total: {pieces, weight, value}|null`.

- [ ] **Step 1: Write the failing tests:** `test_a_price_group_balance_is_the_sum_of_its_colour_variations` (two variations of «الوبين — أبيض», receipts 10 pcs/81.25 kg/1625 and 5 pcs/65 kg/1300 → group 15 / 146.25 / 2925); `test_stock_balances_filter_by_price_group_lists_its_variations_and_a_total` (page `table.data` = the 2 variations, `group_total.weight` = '146.25'); `test_low_stock_alerts_track_colour_variations`.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement; remove every `mainProduct`/`withoutMainItems`/`sub_items` path in the listed files; labels use `PriceGroupSearch::labels`.
- [ ] **Step 4:** Run the new tests + `InventoryPagesTest|StockFollowUpTest|ItemStockCard|StockAlert` — PASS; `npm run typecheck` clean.
- [ ] **Step 5:** Commit `feat(stock): kg balances per price group instead of the main item`.

### Task 3: Posting documents without main-item lines

**Files:**
- Modify: `Modules/Accounting/Services/{InvoiceStockService,GoodsReceiptService,DeliveryNoteService,SalesReturnService,StockAdjustmentService,PurchaseInvoiceStockPosting,ProductionOrderService}.php`
- Delete: `Modules/Accounting/Support/MainItemShare.php`
- Test: `Modules/Accounting/tests/Feature/{InvoiceStockFlowTest,InventoryFlowTest,SalesReturnPagesTest,WeightVarianceTest}.php` (fixtures to group lines), `ProductionOrderPagesTest::test_posting_issues_weight_and_quantity_items_and_shares_the_cost` (piece → variation)

**Interfaces — Consumes:** Task 1 ledger. **Produces:** every weight movement is per variation `product_id` with quantity = pieces, weight = kg share, value = kg share × kg cost.

- [ ] **Step 1: Write the failing test** `test_a_group_line_received_in_two_receipts_splits_kg_and_value_by_its_pieces` (Review Focus 1): purchase order group line, pieces W45-أبيض 10 and W60-أبيض 5, actual 150 kg at 20; receipt 1 takes W45 only, receipt 2 W60 → movement values follow each piece's theoretical weight share and sum to 3000.
- [ ] **Step 2:** Run — FAIL (receipt still reads `mainRows`).
- [ ] **Step 3:** Implement: remove `isMainItem`/`mainRows`/main-line branches; partial receipts/deliveries of a group line use `WeightPieces` shares per piece; `ProductionOrderService::stockRows` moves variations like delivery notes (rebase onto the production-order commit of session `-ea` first if it has landed).
- [ ] **Step 4:** Run `InvoiceStockFlowTest|InventoryFlowTest|SalesReturn|WeightVariance|ProductionOrder|PriceGroupLinesTest` — PASS.
- [ ] **Step 5:** Commit `feat(stock): documents post weight lines per colour variation`.

### Task 4: Pricing without an owner item

**Files:** `Modules/Items/Support/PriceResolver.php`, `Modules/Items/Support/ProductSearch.php` (payload price), `Modules/Accounting/Services/CustomerHistoryService.php` if it keys last prices by owner. Test: `Modules/Items/tests/Unit/DocumentLinePriceTest.php`.

- [ ] **Step 1:** Failing test `test_a_colour_variation_is_priced_from_its_price_group_kg_price` — variation of «الوبين — أبيض» with list «نقدي» kg price 20, piece 8.125 kg → unit price per kg 20 and no lookup through any other product.
- [ ] **Step 2:** Run — FAIL. **Step 3:** Remove the owner swap in `forDocumentLines`; weight variations resolve through `KgPriceResolver`. **Step 4:** Run `DocumentLinePriceTest|PriceGroupLinesTest|SalesQuotationPagesTest` — PASS. **Step 5:** Commit `feat(pricing): weight items price from their price group`.

### Task 5: Items grid and document services

**Files:**
- Server: `Modules/Accounting/Support/{ItemsGrid,ItemsGridState,ItemsGridTotals,ItemLineGroups,WeightPieces,ReturnPieces,PurchaseOrderConsumption,DirectPurchaseOrderDraft,PurchaseOrderMainItems,ConsolidatedPurchaseOrderTabs}.php`, `Http/Controllers/Admin/{ItemsGridController,PurchaseInvoiceController,ConsolidatedPurchaseOrderController}.php`, `Http/Requests/Admin/Concerns/ValidatesItemsGrid.php` and the document requests, the eight document services, `ConsolidatedPurchaseOrder{Service,PickerService}.php`, `routes/web.php` (`match-pieces` removed)
- Vue: `Pages/parts/items-grid/{ItemsGrid.vue,PiecesPanel.vue,useItemsGrid.ts,api.ts,types.ts,ItemDetailsModal.vue}`, `Pages/ConsolidatedPurchaseOrder/{Add.vue,types.ts,tabs/ItemsTab.vue,parts/PurchaseOrderPicker.vue,parts/SubItemsTable.vue,parts/MainItemSummary.vue}`
- Test: `ItemsGridEndpointsTest`, `UnifiedItemsGridTest`, `ItemsGridBarcodeAndPiecesTest`, `OrderItemsSubItemPlacementTest` (rewrite as group placement), `ConsolidatedPurchaseOrder*` tests, `PurchaseOrderSubItemSearchTest`, `WeightLineAmountTest`

**Interfaces — Produces:** grid row payload without `main_product`/`has_sub_products`; `ItemsGrid::searchPieceOutputs(string $term, ?int $groupId)` (variations of the group only); validation rule: pieces require `category_finish_id`.

- [ ] **Step 1:** Failing tests: `test_a_line_with_pieces_but_no_price_group_is_refused` (Review Focus 5, message «Sub-items belong to a price group line; pick the colour group first.»); `test_searching_a_colour_variation_offers_its_group_line` (choosing a variation adds/uses the group line, never a main item).
- [ ] **Step 2:** Run — FAIL. **Step 3:** Delete `ensureMainItemsHaveSubItems`, `matchPiecesByName`, `subProductOptions` use, `mainProductLabels`, the source-item (cutting recipe) column in `PiecesPanel`, and the Vue `main_product` flows; consolidated PO groups by price group. **Step 4:** Run the listed tests + `npm run typecheck` — PASS. **Step 5:** Commit `feat(items-grid): group lines only; no main-item lines`.

### Task 6: Search and catalog admin

**Files:** `Modules/Items/Support/ProductSearch.php` (`idsWithSubProducts`, `subProductOptions`, `mainProductLabels` removed), `Support/Search/SearchFilters.php`, `Repositories/ItemTreeRepository.php`, `Services/{ItemTreeService,ItemCardService}.php`, `Support/{ItemCard,ItemCardRules}.php`, `Http/Controllers/Admin/ItemTreeController.php`, `Http/Requests/Admin/ItemTreeCardRequest.php`, `routes/web.php` (`tree.pieces`), Vue `Pages/ItemTree/{Index.vue,parts/ItemCardModal.vue,parts/ItemTreeCategory.vue,parts/types.ts,parts/useItemTree.ts}`; `Entities/ItemTypeProfile.php` + `Enums/ItemSubItemMode.php` (drop the `aluminium` mode). Tests: `ItemTreePagesTest`, `ItemTreeOperationsTest`, `MainProductTreeTest` (delete), `ProductSearchTest`, `ItemTypeProfilesTest`.

- [ ] **Step 1:** Failing test `test_the_tree_is_category_item_colour` — category «الوبين» → item «ثابت مجرين W45» → its colours; no node for a main item.
- [ ] **Step 2–4:** Run FAIL → implement → run listed tests PASS, typecheck clean. **Step 5:** Commit `feat(items): category → item → colour tree, no main items`.

### Task 7: Importers and schema

**Files:** delete `Modules/Items/Services/MainProductLinker.php`, `Console/Commands/LinkMainProducts.php` (and its registration), `isMainItemRow` in `Services/LegacyItemsImporter.php`, main-item code in `Services/Itqan/ItqanItemsStage.php`; `database/seeders/DemoPurchasingSeeder.php`; new migration `Modules/Items/database/migrations/2026_11_25_100001_drop_main_product_id_from_items_products.php` (drop FK/index then column, guarded by `Schema::hasColumn`); `Product` fillable/relations. Tests: `LegacyImportMainItemTest` (delete), `LegacyImportMappingTest`, `DemoPurchasingScenariosTest`.

- [ ] **Step 1:** Gate test `Modules/Items/tests/Unit/NoMainItemTest::test_products_have_no_main_product_column` (`Schema::hasColumn('items_products','main_product_id')` is false).
- [ ] **Step 2–4:** FAIL → implement → `grep -rn "main_product\|mainProduct\|MainItem\|isPiece" Modules --include=*.php --include=*.vue --include=*.ts` returns only migrations and this plan's history; full Accounting + Items + Core suites PASS (baseline list not grown); typecheck clean.
- [ ] **Step 5:** Commit `refactor(items): drop main_product_id`.

### Task 8: Docs and hand-over

- [ ] Update `.claude/skills/global-components/SKILL.md` (price-group stock, no main item), the redesign spec phase table (3 and 4 done), and the ledger. Commit `docs: catalog phases 3-4 done`.
- [ ] Final whole-branch review (fresh reviewer, most capable model) per executing-plans; fix Critical/Important with RED→GREEN.

## Follow-up plans (separate documents)

- **C — owner review page:** category map (505 rows) + top exception groups with pre-filled defaults; decisions saved and exported to `storage/app/itqan/category-map.csv` + `exception-decisions.csv`, which the importer reads.
- **D — rehearsal and cutover runbook:** PostgreSQL copy of production, import twice with a reconciliation report, then the production window (backup → down → migrate → delete old catalogue/test documents → import → reconcile → `route:clear` → up), with the owner's two approvals.

# Phase 2 · S1 — price-group lines in the items grid (sales quotation + sales order)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** In the shared items grid one search finds «التصنيف — اللون» price groups, quantity items and colour
variations; a price group becomes a main line priced per kg with its variations as pieces; sales quotations and
sales orders save and reopen such lines.

**Architecture:** the main line keeps the grid's existing "main line + pieces" mechanism, keyed by
`category_finish_id` instead of a main product (`product_id` stays null on that line). Kg prices come from
`items_category_finish_prices` through a new resolver. Legacy main-product lines keep their code path.

**Tech stack:** Laravel 12 (nwidart), Inertia + Vue 3 + TS. Tests: `vendor/bin/phpunit -c phpunit.catalog.xml --no-coverage <path>`.

**Spec:** `docs/superpowers/specs/2026-11-22-catalog-phase-2-document-lines-design.md` (D2-1..D2-8).

## Global constraints

- Project skills: `laravel`, `inertia-views`, `global-components`, `translations`, `permissions`.
- Arabic strings in `Lang/ar.json`, Modern Standard Arabic; `__('English')` / `t('English')`.
- Migrations are new files, guarded with `Schema::hasColumn`; FK names short (`acct_<table>_cat_fin_fk`).
- Never touch the server DB. Commit only this slice's files (other sessions' work shares the tree).

## Review focus

1. A variation of colour A dropped into a group line of colour B → refused on save, never silently re-priced.
2. A group line with zero pieces, or pieces with zero weight → refused (same messages as main items today).
3. Changing the party re-prices group lines too (kg price from the new party's list).
4. Reopening a saved quotation/order shows the group label, kg price and pieces exactly as saved.
5. A legacy document (main product + pieces) still opens and saves unchanged.

---

### Task 1: `category_finish_id` on document lines

**Files:** Create `Modules/Accounting/database/migrations/2026_11_22_100001_add_category_finish_to_document_items.php`;
modify the item entities of: sales quotation, sales order, sales invoice, sales return, purchase order, purchase
invoice, purchase return (fillable + `categoryFinish(): BelongsTo`) — through one trait
`Modules/Accounting/Entities/Concerns/HasPriceGroup.php` (`initializeHasPriceGroup` merges fillable; relation).
**Test:** `Modules/Accounting/tests/Feature/PriceGroupLinesTest.php::test_document_lines_carry_a_price_group` —
every listed `_items` table has the column; a line saves and loads `categoryFinish`.

### Task 2: kg price of a price group

**Files:** Create `Modules/Items/Support/KgPriceResolver.php`:
`forGroups(array $groupIds, LinePriceContext $context, string $side): array<int, float>` —
no party → 0; party list (`PartyPricing::for`) row → its sell/buy (source × factor like `PriceResolver::price`);
else first active list by `sort_order`; else 0; divided by the exchange rate.
**Tests** (`Modules/Items/tests/Unit/KgPriceResolverTest.php`): party list price; buy side; general-list fallback;
no party = 0; derived row (source × factor); exchange rate.

### Task 3: one search + group payload (server)

**Files:** `Modules/Accounting/Support/ItemsGrid.php`, `ItemsGridController`, `routes/web.php` (items-grid group),
create `Modules/Items/Support/PriceGroupSearch.php`.
- `PriceGroupSearch::options(string $term, int $limit): array<int, string>` — active groups of weight categories,
  matched on category or colour name (all words, hamza-insensitive via `TextNormalizer`), label «التصنيف — اللون».
- `ItemsGrid::searchProducts($term)` returns `[{id, label, kind: 'group'|'item', group_label?, main_label?}]`:
  groups first (≤ 8), then items. A weight profile with colour variations is replaced by its variations
  (label «W45 — أبيض», `group_label`); legacy weight items stay as they are.
- `ItemsGrid::groupPayload($groupId)` → `{id, label, unit_price, pricing_type: 'weight'}` via `KgPriceResolver`;
  `productPayload` of a variation adds `group: {id, label, unit_price}`.
- `searchPieceOutputs(..., groupId)` inside a group line → that group's variations only.
- `linePrices($productIds, $groupIds)` → `{products: {...}, groups: {...}}`.
**Tests** (`PriceGroupLinesTest`): search returns a group and a variation for «الوبين»; piece search inside a group
returns only its colour; group payload carries the party's kg price; variation payload carries its group.

### Task 4: validate and save group lines (quotation + order)

**Files:** `ValidatesItemsGrid` (`items.*.category_finish_id` exists; `gridItems()` keeps rows with a product or a
group), `ItemsGrid::ensureMainItemsHaveSubItems` (group rows need weighed pieces; every piece is a variation of that
group → `DomainException` «:sub is not in :group»), `SalesQuotationService`, `SalesOrderService`
(store `category_finish_id`, weight pricing, description = group label), `ItemsGridState::rows`
(group label, `category_finish_id`, `pricing_type` weight, `has_sub_products` true).
**Tests:** quotation saves a group line with two pieces, kg = sum of piece weights, total = kg × price; reopening
returns the same row; wrong-colour piece refused; group without pieces refused; legacy main-item quotation unchanged.

### Task 5: grid client

**Files:** `items-grid/types.ts`, `api.ts`, `useItemsGrid.ts`, `GridSearchDropdown.vue`, `ItemsGrid.vue`,
`PiecesPanel.vue` (if it reads `product_id` of the line).
- `GridRow.category_finish_id`; `SearchResult.kind`, `group_label`; `ProductPayload.group`.
- `chooseResult`: `kind === 'group'` → `chooseGroup` (row becomes the group line, opens sub-items);
  a variation with `group` → `placeInGroup` (find the line with that `category_finish_id` or turn the row into it).
- Piece search inside a group line passes the group id; totals/prices/stock requests include group lines.
- Tabs off: `groups` default false.
- Dropdown shows a group with a layers icon and «تصنيف · بالوزن», a variation with its group label.
**Verify:** `npm run typecheck`, `npm run build`; drive a sales quotation in the browser on the sample DB.

### Task 6: prints + reservation

**Files:** quotation/order PDF views (line label = group label when `product_id` is null),
`StockLevelService::reserved` (open sales orders reserve each piece's variation by its piece count).
**Tests:** order PDF renders a group line; reserved quantity of a variation = its piece count on an open order.

---
name: global-components
description: Registry of the shared, system-wide building blocks in crownsystem that MUST be reused instead of re-implemented — user/customer/supplier display (UserCell + UserCellData, user_display_html, avatar_url), party lookups & role scopes, status badges, number/money formatting, reference numbers, safe delete, PDFs, product search, the items grid. Use whenever displaying a user/party, a status, a number, a product, or before writing any helper that might already exist.
---

# Global building blocks — use these, never re-implement

Rule: if something is displayed or computed in more than one place, it has ONE source in the codebase. Changing that source changes the whole system. Before writing markup/helpers, check this list. If you add a new global piece, add it here in the same commit.

## 1. Users / customers / suppliers / companies (all are `Modules\Core\Entities\User`)

| Need | Use | Never |
|---|---|---|
| Show a user (avatar + name + email/phone) in a Vue table or page | `<UserCell :name :avatar :detail :href />` (`resources/js/Components/table/UserCell.vue`); the server sends `Modules\Core\Support\UserCellData::of($user)` (null-safe; TS type `UserCellData` in `resources/js/types/user.ts`) | an inline `<img>` + name, or building the array by hand |
| Same, in Blade HTML | `{{ $user->user_display_html }}` (returns `HtmlString`; null-safe: `{{ $doc->client?->user_display_html ?? '-' }}`) | hand-built `<img>` + name + email markup |
| Avatar URL only | `$user->avatar_url` (uploaded media, else cached initials PNG `data:` URL) | `getFirstMediaUrl('avatar')`, `asset($user->avatar_url)` — `asset()` breaks `data:` URLs |
| Plain text name (PDF, select option label, toast, notification, string concat) | `$user->name` | putting `user_display_html` into PDFs/selects |

- The Blade design lives in ONE file: `Modules/Core/resources/views/partials/user-display.blade.php`; the Vue one in `UserCell.vue`. Change them there only.
- The accessor is `User::getUserDisplayHtmlAttribute()` — always call it as the property `->user_display_html`, never as the method.
- Performance: `UserCellData::of()` reads `avatar_url`, so eager-load `<relation>.media` (`->with('client.media')`); when the rows ARE users, `->with('media')`.
- Party relations on documents: `client()` (`client_id`, customer OR supplier depending on doc), `supplier()`, `shippingCompany()`, `deliveryCompany()`, `insuranceCompany()`, `clearanceCompany()`, `createdBy()`, `postedBy()`, `cancelledBy()` — all `belongsTo(User::class, '<fk>')`.
- Role filtering: `User::query()->customerRole()` / `->supplierRole()` / `->partyRole()` (customer or supplier). Need another role? add a `scope<Role>Role()` on `User`, don't write `whereHas('roles', …)` inline.
- Party field on a document form or filter: a JSON endpoint returning `Modules\Accounting\Support\PartySelect::lookup('supplier'|'customer'|'any', $term)` (`list<{value, label}>`, scoped search on name/phone/email) feeding the Vue `RemoteSelect` (`resources/js/Components/form/RemoteSelect.vue`) or `CreatableSelect` (§9). Never a select over all users.
- Quick-create a party with its ledger account: `Modules\Accounting\Support\QuickCreate::party($data, 'Supplier')`.

## 2. Status / enums

- Enums live in `Modules/<Module>/Enums` and implement the project contracts `Modules\Core\Contracts\HasLabel` (`getLabel(): ?string`, returns `__('English')`) + `Modules\Core\Contracts\HasColor` (`getColor(): string|array|null`, returns a tone name `gray|info|warning|success|danger|primary`), plus `Modules\Core\Contracts\HasIcon` when it has an icon, and `static options()` for selects. New enums MUST follow this (not the legacy `label()/color()` style).
- Vue badge: the server sends `Modules\Accounting\Support\StatusBadge::of($enum)` → `{label, tone}` (tone mapped by `StatusBadge::tone()`) for `resources/js/Components/ui/Badge.vue`. Blade/PDF: `$record->status?->getLabel()`. Never write an inline colour map in a controller, view or page.

## 3. Numbers, money, quantities

- `Modules\Accounting\Support\Number::money($v)` (4 dp, trailing zeros stripped), `::quantity($v)` (3 dp), `::clean($v, $precision)`, `::normalizeQuantity($v)`.
- Use these instead of `number_format()` and instead of private `formatDecimal()` helpers.
- DB precision: money & quantities `decimal(18,4)`, rates/percentages `decimal(8,4)`, exchange rates `decimal(18,8)`, unit/average cost `decimal(20,6)`. Casts `'decimal:4'` etc.

## 4. Dates

Display format `d/m/Y` (datetime `d/m/Y H:i`). Store as `date`/`datetime` casts.

## 5. Documents & references

- Reference numbers: `app(Modules\Core\Services\ReferenceNumberService::class)->generate('<SEQUENCE_CODE>', $date)` (`preview()` for the form placeholder). Never build `PO-0001` by hand.
- PDFs: service extending `Modules\Core\Services\PdfService` → `streamPdf($view, $data, $filename)`; layout + partials in `core::pdf.partials.*` (see `inertia-views` §PDF).
- Barcodes/QR: `PdfService::generateBarcodeSvg()/generateBarcodeForPdf()/generateQrCode()`, Blade `<x-core::barcode>`.

## 6. Deleting

`Modules\Core\Support\SafeDelete::attempt(fn () => $record->delete())` in the Service (returns false on an FK violation), and `back()->with('error', SafeDelete::message())` in the controller. Products expose `deletionBlocker()`.

## 7. Products

- Search/options/labels/payloads: `Modules\Items\Support\ProductSearch` (`options()`, `label()`, `labels()`, `salesItemPayload()`, `subProductOptions()` …). Never write a raw `like` search on products (300k rows, FULLTEXT index).
- Image: `$product->image` accessor, not `getFirstMediaUrl('product')`.
- Stock on a product (perpetual inventory): quantities come ONLY from inventory documents via `StockLedgerService`. `items_products.stock_quantity` / `stock_weight` are a ledger cache and are NOT fillable — never write them from a form, API or import. Levels of one item (main item = sum of sub-items): `StockLevelService::productLevels($id, $warehouseId)`; read-only summary (levels, `StockStatus`, average cost, per-warehouse balance + primary shelf): `StockLevelService::productSummary($product)`, sent by `Modules\Items\Support\ProductStockSummary::of($product)` and shown by `Modules/Items/resources/js/Pages/Product/parts/StockSummaryPanel.vue`. Product form inventory tab + limit fields: `Pages/Product/parts/InventoryTab.vue` + `StockLimitFields.vue`.
- **Document items grid (ONE items table for every trade document)**: see §9 (`ItemsGrid.vue` + `Modules\Accounting\Support\ItemsGrid`). Never build another items table for a document.
  - Grid state from saved lines: `Modules\Accounting\Support\ItemsGridState::rows($items, $rowKeys, $pieceKeys)`.
  - Sub-items (pieces) of a line: table `<doc>_item_pieces`, model extending `Modules\Accounting\Entities\DocumentItemPiece` (set `TABLE` + `COLUMNS`). Weight math and saving ONLY through `Modules\Accounting\Support\WeightPieces` (`line()` for the kg/pieces part of a line, `normalize()`, `sync()` keeps piece ids, `weight()`). Returns: `Modules\Accounting\Support\ReturnPieces` (returnable piece counts, prorated kg, grid rows).
  - PDF: sub-item rows via `@include('accounting::pdf.partials.item-pieces', ['item' => $item, 'number' => $n, 'columns' => <table columns>])`.
  - Grouping lines for display: `Modules\Accounting\Support\ItemLineGroups::byLabel($lines, 'group', '<order_item_id key>')` (lines that carry the main item label, e.g. GRN/delivery note) or `::byMainItem($lines)` (flat sub-item product lines; one query on `main_product_id`). Both return `[['key', 'main', 'label', 'lines' => [index => line]]]`, keeping the original indexes. Inventory documents use it through `Modules\Accounting\Support\InventoryLines`.
- Stock limits: `max_limit` (≈6 months), `min_limit_1` (≈3 months → warning + stock follow-up), `min_limit_2` (≈1 month → must order). Status only through `Modules\Accounting\Support\StockStatus` and `Product::scopeLowStock()`. `low_stock_threshold`, `stock_status`, `allow_backorder` are legacy e-commerce columns — not used. Cut-piece length for cutting recipes is `length`, not `min_limit_1`.
  - Limit rules (max > min1 > min2 > 0, or all zero = unmonitored), monitored check, edit permission: `Modules\Accounting\Support\StockLimits` (`errors()`, `isMonitored()`, `canEdit()`). Form fields: `StockLimitFields.vue` — never add limit inputs by hand.
  - Stock follow-up level (warning / must order / out, null = normal or unmonitored): `Modules\Accounting\Enums\StockAlertLevel::for($product, $qty)`; category + its permission: `StockFollowUpCategory::for($product)` (weight = aluminum, else parts).
  - Follow-up state + notifications: `Modules\Accounting\Services\StockAlertService::evaluate($productId)` — called by `StockLedgerService` after every movement and by `StockLimitObserver` after a limit change (which also writes `StockLimitChange`). An operation that issues then re-receives the same item wraps itself in `StockAlertService::batch()`.
  - Order suggestions (by max / by last 6 months withdrawals), withdrawals, daily ledger, day movements: `Modules\Accounting\Services\StockFollowUpService`. Days use `config('accounting.company_timezone')`.
  - Item colour: `$product->effective_color` (own colour, else its main item's); suggestions list `Product::colorOptions()`.
- Link from a stock movement to its document: `StockMovement::sourceUrl()`.

## 7b. Notifications

`Modules\Core\Services\NotificationService::send()` for any notification; `resolve($event, $subject)` marks the open notifications of one record as handled («تمت المعالجة», read, never deleted) — shown in the bell and notifications page from `present()['resolved_at']`.

## 8. Feedback

The server flashes `back()->with('success'|'error', __('...'))`; `resources/js/Components/ui/Toasts.vue` shows it. Confirm with `confirm()` from `@/lib/confirm`. Never `alert()` / `window.confirm()`.

## 9. Inertia + Vue panel (see the `inertia-views` skill)

| Need | Use | Never |
|---|---|---|
| Row actions of a list (View, Info, Edit, Delete) and add/edit modals | `Modules/Items/resources/js/Pages/parts/` (`RecordActions`, `useRecordModal`, `useRecordDelete`, `RecordInfoModal`, `RecordViewModal`) + `Modules\Items\Support\RecordInfo::of($model)` for the `<route>.info` JSON | a menu or modal per page |
| A status enum as a badge | `Modules\Accounting\Support\StatusBadge::of($enum)` → `{label, tone}` for `Badge.vue` | mapping colors in each controller |
| Select options from enums, accounts, branches, currencies | `Modules\Accounting\Support\SelectOptions` | `->map(fn ...)` per controller |
| Payment allocations on a voucher / invoice / return page | `PaymentAllocationPanel::of($record)` (`Modules\Accounting\Support`) + `<PaymentAllocationPanel :panel />` (`Modules/Accounting/resources/js/Pages/parts/PaymentAllocationPanel.vue`) | a payments table per page |
| Item lines of any document (sales/purchase/inventory/production) | Vue `Modules/Accounting/resources/js/Pages/parts/items-grid/ItemsGrid.vue` + `DocumentTotals.vue`; server `Modules\Accounting\Support\ItemsGrid` (search, line payload, party price, barcode, pieces, stock), endpoints `accounting.items-grid.*` (`ItemsGridController`), one entry per document in `ItemsGridDocuments`, line rules in `Http/Requests/Admin/Concerns/ValidatesItemsGrid`, amounts in `DocumentLineTotals` / `ItemsGridTotals`. Reference page: `Pages/SalesQuotation/*` + `SalesQuotationService` | a grid or price rule per document |
| Item card rules (type attributes, aluminium/model fields, main-item and weight-pricing rules, ledger account options) | `Modules\Items\Support\ItemCardRules` (used by the product card and the tree card); duplicate-name rule `Modules\Items\Support\ItemCard::duplicateNameError` | rules inside a page or form |
| An item's stock summary and links | `Modules\Items\Support\ProductStockSummary` (from `StockLevelService::productSummary`) | querying stock in a controller |
| The item tree's right-click menu (what each node allows) | `Modules\Items\Services\ItemTreeService::nodeActions()` / `allows()` | deciding actions in Vue only |
| A simple Accounting lookup (create, update, active switch, safe delete) | extend `Modules\Accounting\Services\LookupService`; the in-row switch is `Modules/Accounting/resources/js/Pages/parts/useActiveToggle.ts` | a service per lookup with copied CRUD |
| A list page's data (filters, sort, pages) | `Modules\Core\Support\AdminTable` + `AdminFilter` on the server, `<DataTable>` in Vue | a hand-paginated query or table |
| Delete a record other records may use | `SafeDelete::attempt(fn () => ...)` in the Service, `back()->with('error', SafeDelete::message())` in the controller | catching `QueryException` per page |
| Service lines and customer materials under the grid (sales orders/invoices) | `items-grid/ServiceLines.vue` + `CustomerMaterials.vue`, passed to totals as `GridContext.extraLines`; server rules in `Modules\Accounting\Support\SalesServiceLine` (`merge`, `rows`, `materialRows`, `defaults`, `options`, `providers`), validation `Http/Requests/Admin/Concerns/ValidatesSalesServiceLines` | service rows built per page |
| A page's own JSON calls (searches, pickers, previews) | `Modules/Accounting/resources/js/Pages/parts/http.ts` (`getJson`/`postJson`, XSRF header, `HttpError` with validation errors); record writes stay on Inertia `router`/`useForm` | `fetch` / `axios` per page |
| Pick a party or lookup with "+ create new" in place | `Pages/parts/CreatableSelect.vue` (RemoteSelect + `QuickCreateModal.vue`) | a separate "add" page or modal per form |
| An endpoint shared by the create and edit forms | `->middleware(AuthorizeAnyPermission::class.':X Create,X Update')` (`Modules\Accounting\Http\Middleware`) | leaving it without permission, or two copies of the route |
| Inventory documents (GRN, delivery note, transfer, adjustment) list/meta/lines/actions | `Modules/Accounting/resources/js/Pages/Inventory/parts/` (`DocumentIndex`, `DocumentMeta`, `LinesTable`, `DocumentActions` + `useDocumentActions`, `SearchPicker`) | a list or action bar per document |
| Receipt / payment voucher pages | `Pages/parts/voucher/` (`VoucherIndex`, `VoucherAdd`, `VoucherEdit`, `VoucherFields`) | copying a voucher page |
| A permission's group and Arabic label | `Modules\Core\Support\PermissionLabels` (`groups`, `summary`, `label`) | word lists inside a page |
| Follow a notification's link | `NotificationService::safeUrl()` | another `str_starts_with('/')` check |
| Panel color and font | `Modules\Core\Support\DashboardTheme` (settings `dashboard_color`, `dashboard_font`) | hard-coded brand colors |
| Texts in Vue | `t('English key')` from `@/lib/i18n`, Arabic in the module `Lang/ar.json` | Arabic text in a `.vue` file |

## 10. Adding a new global piece

1. Put it in `Modules/Core` (or the owning module's `Support/`, `Concerns/`), or for UI in `resources/js/Components` / the module's `resources/js/Pages/parts/` — not inside one page.
2. Give it ONE entry point (accessor, static helper, Vue component or composable).
3. Replace existing duplicates in the same change if the user asked for system-wide consistency.
4. Document it in this file.

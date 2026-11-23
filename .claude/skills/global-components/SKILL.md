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
| Same, as an array in PHP (Inertia props, JSON) | `$user->user_cell` (accessor → `UserCellData::of($this)`) | building `['name' => …, 'avatar' => …]` by hand |
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
- Prices & cost on a product: the item card has ONE price, `base_price` (the default sale price). `sale_price`, `wholesale_price`, `discount_*` and `cost_price` are legacy columns (API/import only) — never read them for pricing or costing. Cost is always the weighted average from the purchases: `StockLedgerService::averageCost($id)` / `averageCosts($ids)` (one query); never fall back to `base_price` for a cost (it would book inventory at a selling price). Sale-side default: `ProductSearch::salesUnitPrice($product, $averageCost, $margin)`.
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
  - Item colour: a variation's colour is `finish_id` (`Modules\Items\Entities\Finish`, the one colours list). A weight item's colour is its price group `category_finish_id` (`CategoryFinish` = category × colour), named with `CategoryFinish::labelFor($parentCategoryIds)`. The legacy free-text `color` / `$product->effective_color` is read by stock follow-up only; the item card no longer writes it.
  - Colours a weight item chooses from and their kg prices: `Modules\Items\Support\CategoryColours` (`for($categoryIds, $include)` for the item card, endpoint `products.colours`; `forCategory($category)` + `priceLists()` for the category page). Saving a category's colours and kg prices: `Modules\Items\Services\CategoryColourService::sync()`. A new colour (with its store mirror in the system «colour» attribute): `Modules\Items\Services\FinishService::findOrCreate()`. The system attribute is `Attribute::isSystem()` and is never edited or deleted from the attributes page.
  - Variations of an item are saved only through `ProductService::syncVariations` (weight rows carry `category_finish_id`; the server names them, copies the parent's measures and writes the colour attribute value; a variation used in a document is refused, not deleted). Vue: `Pages/Product/parts/ColoursTab.vue` (weight) / `VariationsTab.vue` (simple), colours loaded by `useCategoryColours.ts`, kg prices shown by `KgPriceTable.vue`. Stock per colour: `ProductStockSummary::variants($product)`.
  - **Price-group lines in documents** (phase 2): a weight line is the price group itself (`<doc>_items.category_finish_id`, `product_id` null; trait `Modules\Accounting\Entities\Concerns\HasPriceGroup`), its pieces are that colour's variations. Who owns a grid line, its description and whether it is weighed: `Modules\Accounting\Support\GridLine` (use it in every document service instead of `filled($item['product_id'])`). Kg price of a group for a party: `Modules\Items\Support\KgPriceResolver::forGroups()` (party list → general list → 0). Search and labels «التصنيف — اللون»: `Modules\Items\Support\PriceGroupSearch` / `CategoryFinish::label()`. Grid endpoints `items-grid.group`, `items-grid.group-prices`, `piece-outputs?group_id=`. Stock: a group line has no main item — each piece moves its variation by its count, with its share of the line's actual weight and value (`WeightPieces::actualShares()`); stock code checks `$item->isPriceGroupLine()` and skips the main-item kg rows and the main-item true-up for it; a purchase invoice at another price revalues each colour to its share instead (`InvoiceStockService::priceGroupRevaluations()`).
- Link from a stock movement to its document: `StockMovement::sourceUrl()`.

## 7b. Notifications

`Modules\Core\Services\NotificationService::send()` for any notification; `resolve($event, $subject)` marks the open notifications of one record as handled («تمت المعالجة», read, never deleted) — shown in the bell and notifications page from `present()['resolved_at']`.

A live update is never part of the action: broadcast a `ShouldBroadcastNow` event only through `Modules\Core\Support\LiveUpdate::send($event)`, and notifications go `database` first, then `Modules\Core\Notifications\Channels\LiveBroadcastChannel` (Reverb) and `PushChannel` (Firebase, only for a user with a device). A stopped Reverb or missing Firebase settings is reported and the save still succeeds — never call `event()` / `broadcast()` for a live update directly, never list `broadcast` or `FcmChannel` in `via()`.

## 8. Feedback

The server flashes `back()->with('success'|'warning'|'error', __('...'))` (warning = partly done); `resources/js/Components/ui/Toasts.vue` shows it. Confirm with `confirm()` from `@/lib/confirm`. Never `alert()` / `window.confirm()`.

## 9. Inertia + Vue panel (see the `inertia-views` skill)

| Need | Use | Never |
|---|---|---|
| Row actions of EVERY table: one ⋮ menu (`RowMenu`) in one order — Activity Log · View · Edit · the table's own actions · Delete (Activity Log/View = `<Entity> View List`, always present; Edit = `Update` + `editable`; Delete = `Delete` + `deletable`; the table's own actions as `DropdownItem`s in its slot) | `resources/js/Components/table/RecordActions.vue` (+ `RowMenu`, `RecordInfoModal`, `RecordViewModal`), `@/lib/useRecordModal`, `@/lib/useRecordDelete`; the `<routes>.info` JSON (`->can('<Entity> View List')`) returns `Modules\Core\Support\RecordInfo::of($model)` (creator card + readable activity timeline from `ActivityLogFormatter::rows()`) | coloured icon buttons in the row, a hand-built `RowMenu` or modal per page, raw JSON in the activity log |
| A status enum as a badge | `Modules\Accounting\Support\StatusBadge::of($enum)` → `{label, tone}` for `Badge.vue` | mapping colors in each controller |
| Select options from enums, accounts, branches, currencies | `Modules\Accounting\Support\SelectOptions` | `->map(fn ...)` per controller |
| Payment allocations on a voucher / invoice / return page | `PaymentAllocationPanel::of($record)` (`Modules\Accounting\Support`) + `<PaymentAllocationPanel :panel />` (`Modules/Accounting/resources/js/Pages/parts/PaymentAllocationPanel.vue`) | a payments table per page |
| Item lines of any document (sales/purchase/inventory/production) | Vue `Modules/Accounting/resources/js/Pages/parts/items-grid/ItemsGrid.vue` + `DocumentTotals.vue`; server `Modules\Accounting\Support\ItemsGrid` (search, line payload, party price, barcode, pieces, stock), endpoints `accounting.items-grid.*` (`ItemsGridController`), one entry per document in `ItemsGridDocuments`, line rules in `Http/Requests/Admin/Concerns/ValidatesItemsGrid`, amounts in `DocumentLineTotals` / `ItemsGridTotals`. Reference page: `Pages/SalesQuotation/*` + `SalesQuotationService` | a grid or price rule per document |
| Item card rules (type attributes, aluminium/model fields, main-item and weight-pricing rules, ledger account options) | `Modules\Items\Support\ItemCardRules` (used by the product card and the tree card); duplicate-name rule `Modules\Items\Support\ItemCard::duplicateNameError` | rules inside a page or form |
| An item's stock summary and links | `Modules\Items\Support\ProductStockSummary` (from `StockLevelService::productSummary`) | querying stock in a controller |
| The item tree's right-click menu (what each node allows) | `Modules\Items\Services\ItemTreeService::nodeActions()` / `allows()` | deciding actions in Vue only |
| An Itqan-style sequence number (category / item code, colour code 001, 002 …) | `Modules\Items\Support\ItemCode` (`nextRoot`, `nextChild`, `forCategory`, `nextFinish`); deleted numbers are never reused | counting codes in a service or page |
| A simple Accounting lookup (create, update, active switch, safe delete) | extend `Modules\Accounting\Services\LookupService`; the in-row switch is `Modules/Accounting/resources/js/Pages/parts/useActiveToggle.ts` | a service per lookup with copied CRUD |
| A list page's data (filters, sort, pages) | `Modules\Core\Support\AdminTable` + `AdminFilter` on the server, `<DataTable>` in Vue | a hand-paginated query or table |
| Wrong input in a list's link (unknown filter, bad date/number, reversed range, unknown sort, page size or page) | automatic: `AdminTable::rows()` runs it through `Modules\Core\Support\TableInput` (types come from `AdminFilter`; a hand-made `AllowedFilter` is a plain value — wrap it with `AdminFilter::typed($filter, AdminFilter::DATE)` etc.), falls back to the default and sends `table.warnings`, which `DataTable` shows above the rows with the filter's label | try/catch per controller, letting Spatie's 400 through, silently ignoring a value |
| Bulk delete of a table | controller `Modules\Core\Http\Controllers\Concerns\DeletesManyRecords` (`bulkDeleteQuery()` = the list's query, `deleteRecord()` = the single `destroy()` rules; `false`, a `DomainException` or a `ValidationException` keeps the row — never a plain `RuntimeException`, it aborts the whole request), route `Route::delete('/', 'destroyMany')->name('destroy-many')->can('<Entity> Delete')`, page `<DataTable :bulk-delete-url="can('<Entity> Delete') ? route('<routes>.destroy-many') : undefined">` (checkboxes + confirmed Delete button come with it). `Modules\Core\Support\BulkDelete` validates the ids (max one page, distinct, all from the list), deletes row by row and flashes success / `warning` (partly) / error with the reasons (Accounting documents: `Modules\Accounting\Http\Controllers\Admin\Concerns\DeletesManyDocuments` + `deleteDocument()`, which also refuses an already-deleted row on lists that show deleted ones) | a `#bulk` Delete button, a service `deleteMany()`, an all-or-nothing transaction, `ids` without max/distinct/scope |
| A partial success message (some rows done, some refused) | `back()->with('warning', __('...'))` → amber toast | `error` for a half-done action |
| «Created By» column of a table | server `AdminTable::...->withCreator()` (row `created_by` = `UserCellData`, eager-loads `createdBy.media`), client `createdByColumn()` from `@/lib/tableColumns` (DataTable draws `type: 'user'` columns with `UserCell`) | a creator column built per page |
| Drag-and-drop row order (setup tables only, never documents) | model `Modules\Core\Entities\Concerns\HasSortOrder` (`sort_order`), controller `Modules\Core\Http\Controllers\Concerns\ReordersRecords` + `sortableModel()`, route `Route::post('/reorder', 'reorder')->name('reorder')->can('<Entity> Update')`, `AdminTable::...->reorderable()`, `<DataTable :reorder-url>`; saving in `Modules\Core\Services\RecordOrderService` | a sort_order form field or a reorder endpoint per page |
| `created_by` filled automatically on a model that did not have it | `Modules\Core\Entities\Concerns\HasCreator` (also defines `createdBy()`) | setting `created_by` in every service |
| Delete a record other records may use | `SafeDelete::attempt(fn () => ...)` in the Service, `back()->with('error', SafeDelete::message())` in the controller | catching `QueryException` per page |
| Service lines and customer materials under the grid (sales orders/invoices) | `items-grid/ServiceLines.vue` + `CustomerMaterials.vue`, passed to totals as `GridContext.extraLines`; server rules in `Modules\Accounting\Support\SalesServiceLine` (`merge`, `rows`, `materialRows`, `defaults`, `options`, `providers`), validation `Http/Requests/Admin/Concerns/ValidatesSalesServiceLines` | service rows built per page |
| A page's own JSON calls (searches, pickers, previews) | `Modules/Accounting/resources/js/Pages/parts/http.ts` (`getJson`/`postJson`, XSRF header, `HttpError` with validation errors); record writes stay on Inertia `router`/`useForm` | `fetch` / `axios` per page |
| Pick a party or lookup with "+ create new" in place | `Pages/parts/CreatableSelect.vue` (RemoteSelect + `QuickCreateModal.vue`); a static list (e.g. the item's categories, `Product/parts/GeneralInfoCard.vue` → `categories.quick-store`) puts a "+" beside its `MultiSelect` and opens `QuickCreateModal` with `fields="name"` | a separate "add" page or modal per form |
| An endpoint shared by the create and edit forms | `->middleware(AuthorizeAnyPermission::class.':X Create,X Update')` (`Modules\Accounting\Http\Middleware`) | leaving it without permission, or two copies of the route |
| Inventory documents (GRN, delivery note, transfer, adjustment) list/meta/lines/actions | `Modules/Accounting/resources/js/Pages/Inventory/parts/` (`DocumentIndex`, `DocumentMeta`, `LinesTable`, `DocumentActions` + `useDocumentActions`, `SearchPicker`) | a list or action bar per document |
| Receipt / payment voucher pages | `Pages/parts/voucher/` (`VoucherIndex`, `VoucherAdd`, `VoucherEdit`, `VoucherFields`) | copying a voucher page |
| A permission's group and Arabic label | `Modules\Core\Support\PermissionLabels` (`groups`, `summary`, `label`) | word lists inside a page |
| Follow a notification's link | `NotificationService::safeUrl()` | another `str_starts_with('/')` check |
| Panel color and font | `Modules\Core\Support\DashboardTheme` (settings `dashboard_color`, `dashboard_font`) | hard-coded brand colors |
| Texts in Vue | `t('English key')` from `@/lib/i18n`, Arabic in the module `Lang/ar.json` | Arabic text in a `.vue` file |
| Height and corners of a field or button | `h-control` (34px) / `h-control-sm` (28px), `rounded-control` (6px); fields take `controlClasses`, buttons `buttonClasses()` / `<Button>` | `h-9`, `h-10`, `rounded-lg` on a field |
| Page width and side gutter | `max-w-page` (1280px) and `px-gutter` (30px) from `admin.css`; a bar that bleeds to the page edge uses `lg:-mx-gutter` | `max-w-[1440px]`, `lg:px-8`, `-mx-8` |
| Password field (show/hide button) | `resources/js/Components/form/PasswordInput.vue`; a link on the label line goes in Field's `#aside` slot | `type="password"` on a TextInput |
| Checkbox with its label | `resources/js/Components/form/Checkbox.vue` | a bare `<input type="checkbox">` + label |
| A message box in a page or form (sent, saved, warning) | `resources/js/Components/ui/Alert.vue` (`tone` info/success/warning/danger) | a coloured `<p>` |
| Sign-in screens | `GuestLayout` (form card + brand panel) and `Components/auth/AuthHeading.vue` for the title | a page-level layout or heading |

## 10. Adding a new global piece

1. Put it in `Modules/Core` (or the owning module's `Support/`, `Concerns/`), or for UI in `resources/js/Components` / the module's `resources/js/Pages/parts/` — not inside one page.
2. Give it ONE entry point (accessor, static helper, Vue component or composable).
3. Replace existing duplicates in the same change if the user asked for system-wide consistency.
4. Document it in this file.

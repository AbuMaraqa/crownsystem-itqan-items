---
name: global-components
description: Registry of the shared, system-wide building blocks in crownsystem that MUST be reused instead of re-implemented — user/customer/supplier display (user_display_html, UserColumn, avatar_url), party selects & role scopes, status badges, number/money formatting, reference numbers, safe delete, PDFs, product search. Use whenever displaying a user/party, a status, a number, a product, or before writing any helper that might already exist.
---

# Global building blocks — use these, never re-implement

Rule: if something is displayed or computed in more than one place, it has ONE source in the codebase. Changing that source changes the whole system. Before writing markup/helpers, check this list. If you add a new global piece, add it here in the same commit.

## 1. Users / customers / suppliers / companies (all are `Modules\Core\Entities\User`)

| Need | Use | Never |
|---|---|---|
| Show a user (avatar + name + email/phone) in Blade/HTML | `{{ $user->user_display_html }}` (returns `HtmlString`; null-safe: `{{ $doc->client?->user_display_html ?? '-' }}`) | hand-built `<img>` + name + email markup |
| Same, in a Filament table | `UserColumn::make('client')` / `UserColumn::make('createdBy')` / `UserColumn::make()` when the row is the user (`Modules\Core\Filament\Tables\Columns\UserColumn`) | `TextColumn::make('client.name')` |
| Same, in a Filament infolist | `TextEntry::make('x')->formatStateUsing(fn ($record) => $record->createdBy?->user_display_html)->html()` (see `InfoAction`) | |
| Avatar URL only | `$user->avatar_url` (uploaded media, else cached initials PNG `data:` URL) | `getFirstMediaUrl('avatar')`, `asset($user->avatar_url)` — `asset()` breaks `data:` URLs |
| Plain text name (PDF, select option label, toast, notification, string concat) | `$user->name` | putting `user_display_html` into PDFs/selects |

- The design lives in ONE file: `Modules/Core/resources/views/partials/user-display.blade.php`. Change it there only.
- The accessor is `User::getUserDisplayHtmlAttribute()` — always call it as the property `->user_display_html`, never as the method.
- Performance: `UserColumn` eager-loads `<relation>.media` automatically. When the table rows ARE users (`UserColumn::make()`), add `->with('media')` to the table query. Outside tables, eager-load `client.media` when listing many records.
- Party relations on documents: `client()` (`client_id`, customer OR supplier depending on doc), `supplier()`, `shippingCompany()`, `deliveryCompany()`, `insuranceCompany()`, `clearanceCompany()`, `createdBy()`, `postedBy()`, `cancelledBy()` — all `belongsTo(User::class, '<fk>')`.
- Role filtering: `User::query()->customerRole()` / `->supplierRole()` / `->partyRole()` (customer or supplier). Need another role? add a `scope<Role>Role()` on `User`, don't write `whereHas('roles', …)` inline.
- Party field on a document form or table filter: `Modules\Accounting\Support\PartySelect::supplier('client_id')` (purchase docs), `::customer()` (sales docs), `::any()` (vouchers, cheques). Returns a Filament `Select` with label, scoped search (name/phone/email) and saved-value label; chain `->required()`, `->live()` etc. Never build a `Select::make('client_id')` over all users.
- Quick-create a party with its ledger account: `Modules\Accounting\Support\QuickCreate::partyForm()` + `QuickCreate::party($data, 'Supplier')`.

## 2. Status / enums

- Enums live in `Modules/<Module>/Enums`, implement Filament `HasLabel` + `HasColor` (`getLabel()` returns `__('English')`, `getColor()` returns `gray|info|warning|success|danger|primary`), plus `static options()` for selects. New enums MUST follow this (not the legacy `label()/color()` style).
- Filament table: `TextColumn::make('status')->label(__('Status'))->badge()->color(SalesInvoiceStatus::class)` (label comes from `getLabel()` automatically).
- Blade: `<x-accounting::inventory.status-badge :status="$record->status" />`. Never write an inline colour map (`$statusStyles = [...]`) in a view.

## 3. Numbers, money, quantities

- `Modules\Accounting\Support\Number::money($v)` (4 dp, trailing zeros stripped), `::quantity($v)` (3 dp), `::clean($v, $precision)`, `::normalizeQuantity($v)`.
- Use these instead of `number_format()` and instead of private `formatDecimal()` helpers in components.
- DB precision: money & quantities `decimal(18,4)`, rates/percentages `decimal(8,4)`, exchange rates `decimal(18,8)`, unit/average cost `decimal(20,6)`. Casts `'decimal:4'` etc.

## 4. Dates

Display format `d/m/Y` (Filament: `->date('d/m/Y')`, datetime `d/m/Y H:i`). Store as `date`/`datetime` casts.

## 5. Documents & references

- Reference numbers: `app(Modules\Core\Services\ReferenceNumberService::class)->generate('<SEQUENCE_CODE>', $date)` (`preview()` for the form placeholder). Never build `PO-0001` by hand.
- PDFs: service extending `Modules\Core\Services\PdfService` → `streamPdf($view, $data, $filename)`; layout + partials in `core::pdf.partials.*`.
- Barcodes/QR: `PdfService::generateBarcodeSvg()/generateBarcodeForPdf()/generateQrCode()`, Blade `<x-core::barcode>`.

## 6. Deleting

`Modules\Core\Support\SafeDelete::run(fn () => $record->delete())` — catches FK violations and shows a toast. Products expose `deletionBlocker()`.

## 7. Products

- Search/options/labels/payloads: `Modules\Items\Support\ProductSearch` (`options()`, `label()`, `labels()`, `salesItemPayload()`, `subProductOptions()` …). Never write a raw `like` search on products (300k rows, FULLTEXT index).
- Image: `$product->image` accessor, not `getFirstMediaUrl('product')`.
- Stock on a product (perpetual inventory): quantities come ONLY from inventory documents via `StockLedgerService`. `items_products.stock_quantity` / `stock_weight` are a ledger cache and are NOT fillable — never write them from a form, API or import. Levels of one item (main item = sum of sub-items): `StockLevelService::productLevels($id, $warehouseId)`; read-only summary (levels, `StockStatus`, average cost, per-warehouse balance + primary shelf): `StockLevelService::productSummary($product)`, rendered by `accounting::livewire.pages.inventory.partials.product-stock-summary`. Product form inventory tab + limit fields: trait `Modules\Items\Livewire\Concerns\ManagesProductStock` (`inventoryTab()`, `stockLimitFields()`).
- **Document items grid (ONE items table for every trade document)**: purchase/sales orders, sales quotations, sales/purchase invoices, purchase quotations and sales/purchase returns all use `accounting::livewire.pages.partials.order-items-grid` (main item row + always-open sub-items table for weight items). Never build another items repeater for a document.
  - Filament page: `use ManagesOrderItemsWithPiecesForm` (`Livewire/Concerns`) → `$this->itemsGrid()` in the schema, `...$this->itemsRules()`, `refreshTotals()` → `refreshDocumentTotals('<discount column>')`, call `$this->refreshItemsGrid()` after every save/action (the grid is `wire:ignore`). Mode hooks: `itemsAreEditable()`, `itemPricesAreEditable()`, `itemActualWeightIsEditable()` (invoice from an order: items locked, scale weight editable), `itemQuantitiesAreEditable()` + `itemQuantityLimitLabel()` (returns: only quantities / piece counts, capped by `max_quantity` / `max_piece_count`), `showsItemDiscount()`, `showsItemLength()`, `showsItemPrices()`, `suggestsAccessories()`, `itemQuantityIsWeight()` (kg-priced documents).
  - Plain Livewire page (no Filament form): `@include` the grid with `statePath` + `rows` + the same flags (see `purchase-quotation/form.blade.php`).
  - Grid state from saved lines: `$this->gridRows($items, $rowKeys, $pieceKeys)` / `Modules\Accounting\Support\ItemsGridState::rows()`.
  - Totals: ONE calculator `accounting::livewire.pages.partials.document-calculator` (`x-data="documentCalculator()"`, `data-document-total*` attributes). Return pages: `ManagesReturnDocumentForm` + `partials/return-document.blade.php`.
  - Sub-items (pieces) of a line: table `<doc>_item_pieces`, model extending `Modules\Accounting\Entities\DocumentItemPiece` (set `TABLE` + `COLUMNS`). Weight math and saving ONLY through `Modules\Accounting\Support\WeightPieces` (`line()` for the kg/pieces part of a line, `normalize()`, `sync()` keeps piece ids, `weight()`). Returns: `Modules\Accounting\Support\ReturnPieces` (returnable piece counts, prorated kg, grid rows).
  - PDF: sub-item rows via `@include('accounting::pdf.partials.item-pieces', ['item' => $item, 'number' => $n, 'columns' => <table columns>])`.
- **Items tables (look of the shared items grid `order-items-grid`)**: every document items table that is not the editable grid itself uses the anonymous components in `Modules/Accounting/resources/views/components/items/` — one main-item row per main (weight) item with its sub-items in an always-open panel underneath; quantity items are normal rows.
  - `<x-accounting::items.table :min-width="'min-w-[52rem]'">` — outer table: `# | Item | Barcode` + `<x-slot:head>` (`th` class `px-2 py-2.5 font-medium`); rows in the default slot.
  - `<x-accounting::items.row :number :label :barcode main>` — outer row (`main` adds the main-item icon/bold); `<x-slot:details>` = small line under the label; default slot = the document's `<td>`s. Attributes (`wire:key`, `class`) go to the `<tr>`.
  - `<x-accounting::items.sub-items :colspan :count :title>` — the sub-items panel (`border-s-2 border-primary/40 bg-primary/[0.03]`, header "Sub Items (n)", optional `<x-slot:actions>`). With `colspan` it is a full-width `<tr>`, without it a standalone `<div>` (cards, Filament column HTML).
  - `<x-accounting::items.sub-table>` — compact table inside the panel: `# | Item | Barcode` + `<x-slot:head>` (`th` class `px-2 py-1 font-medium`), optional `<x-slot:lead>` column before `#` (checkboxes).
  - `<x-accounting::items.sub-row :number="'1.2'" :label :barcode :highlight>` — sub-item row; `<x-slot:lead>`, `<x-slot:details>`, default slot = the `<td>`s.
  - Grouping lines for display: `Modules\Accounting\Support\ItemLineGroups::byLabel($lines, 'group', '<order_item_id key>')` (lines that carry the main item label, e.g. GRN/delivery note) or `::byMainItem($lines)` (flat sub-item product lines; one query on `main_product_id`). Both return `[['key', 'main', 'label', 'lines' => [index => line]]]`, keeping the original indexes for `wire:model="lines.{index}..."`.
  - Used by the remaining Blade views (PDFs and legacy pages). The Vue pages use the items grid of section 10; the consolidated purchase order is a Vue page now.
- Stock limits: `max_limit` (≈6 months), `min_limit_1` (≈3 months → warning + stock follow-up), `min_limit_2` (≈1 month → must order). Status only through `Modules\Accounting\Support\StockStatus` and `Product::scopeLowStock()`. `low_stock_threshold`, `stock_status`, `allow_backorder` are legacy e-commerce columns — not used. Cut-piece length for cutting recipes is `length`, not `min_limit_1`.
  - Limit rules (max > min1 > min2 > 0, or all zero = unmonitored), monitored check, edit permission: `Modules\Accounting\Support\StockLimits` (`errors()`, `isMonitored()`, `canEdit()`). Form fields: `ManagesProductStock::stockLimitFields()` + `colorField()` — never add limit inputs by hand.
  - Stock follow-up level (warning / must order / out, null = normal or unmonitored): `Modules\Accounting\Enums\StockAlertLevel::for($product, $qty)`; category + its permission: `StockFollowUpCategory::for($product)` (weight = aluminum, else parts).
  - Follow-up state + notifications: `Modules\Accounting\Services\StockAlertService::evaluate($productId)` — called by `StockLedgerService` after every movement and by `StockLimitObserver` after a limit change (which also writes `StockLimitChange`). An operation that issues then re-receives the same item wraps itself in `StockAlertService::batch()`.
  - Order suggestions (by max / by last 6 months withdrawals), withdrawals, daily ledger, day movements: `Modules\Accounting\Services\StockFollowUpService`. Days use `config('accounting.company_timezone')`.
  - Item colour: `$product->effective_color` (own colour, else its main item's); suggestions list `Product::colorOptions()`.
- Link from a stock movement to its document: `StockMovement::sourceUrl()`.

## 7b. Notifications

`Modules\Core\Services\NotificationService::send()` for any notification; `resolve($event, $subject)` marks the open notifications of one record as handled («تمت المعالجة», read, never deleted) — shown in the bell and notifications page from `present()['resolved_at']`.

## 8. Filament building blocks (`Modules/Core/Filament/Forms/Components`)

`CreateAction`, `EditAction`, `ViewAction`, `DeleteAction`, `InfoAction` (created-by + activity log — add to every Index row actions), `TextInput`, `Textarea`, `FileUploadWithPreview`, `MapPicker`, `CategoryTreeSelect`. Global Filament defaults: `Modules/Core/Providers/ConfigServiceProvider.php`.

## 9. Feedback

`Masmerise\Toaster\Toaster::success(__('...'))` / `::error()`. Not LivewireAlert, not `Notification::make()` in new code.

## 10. Inertia + Vue panel (see the `inertia-views` skill)

| Need | Use | Never |
|---|---|---|
| Show a user / customer / supplier in a Vue table | `<UserCell :name :avatar :detail :href />` (`resources/js/Components/table/UserCell.vue`); the server sends `Modules\Core\Support\UserCellData::of($user)` (TS type `UserCellData` in `resources/js/types/user.ts`) | an inline `<img>` + name, or building the array by hand |
| Row actions of a list (View, Info, Edit, Delete) and add/edit modals | `Modules/Items/resources/js/Pages/parts/` (`RecordActions`, `useRecordModal`, `useRecordDelete`, `RecordInfoModal`, `RecordViewModal`) + `Modules\Items\Support\RecordInfo::of($model)` for the `<route>.info` JSON | a menu or modal per page |
| A status enum as a badge | `Modules\Accounting\Support\StatusBadge::of($enum)` → `{label, tone}` for `Badge.vue` | mapping colors in each controller |
| Select options from enums, accounts, branches, currencies | `Modules\Accounting\Support\SelectOptions` | `->map(fn ...)` per controller |
| Payment allocations on a voucher / invoice / return page | `PaymentAllocationPanel::of($record)` (`Modules\Accounting\Support`) + `<PaymentAllocationPanel :panel />` (`Modules/Accounting/resources/js/Pages/parts/PaymentAllocationPanel.vue`) | the Livewire panel in new pages |
| Item lines of any document (sales/purchase/inventory/production) | Vue `Modules/Accounting/resources/js/Pages/parts/items-grid/ItemsGrid.vue` + `DocumentTotals.vue`; server `Modules\Accounting\Support\ItemsGrid` (search, line payload, party price, barcode, pieces, stock), endpoints `accounting.items-grid.*` (`ItemsGridController`), one entry per document in `ItemsGridDocuments`, line rules in `Http/Requests/Admin/Concerns/ValidatesItemsGrid`, amounts in `DocumentLineTotals` / `ItemsGridTotals`. Reference page: `Pages/SalesQuotation/*` + `SalesQuotationService` | a grid or price rule per document |
| Item card rules (type attributes, aluminium/model fields, main-item and weight-pricing rules, ledger account options) | `Modules\Items\Support\ItemCardRules` (used by the product card, the tree card and the legacy concerns); duplicate-name rule `Modules\Items\Support\ItemCard::duplicateNameError` | rules inside a page or form |
| An item's stock summary and links | `Modules\Items\Support\ProductStockSummary` (from `StockLevelService::productSummary`) | querying stock in a controller |
| The item tree's right-click menu (what each node allows) | `Modules\Items\Services\ItemTreeService::nodeActions()` / `allows()` | deciding actions in Vue only |
| A simple Accounting lookup (create, update, active switch, safe delete) | extend `Modules\Accounting\Services\LookupService`; the in-row switch is `Modules/Accounting/resources/js/Pages/parts/useActiveToggle.ts` | a service per lookup with copied CRUD |
| A list page's data (filters, sort, pages) | `Modules\Core\Support\AdminTable` + `AdminFilter` on the server, `<DataTable>` in Vue | a hand-paginated query or table |
| Delete a record other records may use | `SafeDelete::attempt(fn () => ...)` in the Service, `back()->with('error', SafeDelete::message())` in the controller (`SafeDelete::run` stays for Livewire) | catching `QueryException` per page |
| Service lines and customer materials under the grid (sales orders/invoices) | `items-grid/ServiceLines.vue` + `CustomerMaterials.vue`, passed to totals as `GridContext.extraLines`; server rules in `Modules\Accounting\Support\SalesServiceLine` (`merge`, `rows`, `materialRows`, `defaults`, `options`, `providers`), validation `Http/Requests/Admin/Concerns/ValidatesSalesServiceLines` | service rows built per page |
| A page's own JSON calls (searches, pickers, previews) | `Modules/Accounting/resources/js/Pages/parts/http.ts` (`getJson`/`postJson`, XSRF header, `HttpError` with validation errors); record writes stay on Inertia `router`/`useForm` | `fetch` / `axios` per page |
| Pick a party or lookup with "+ create new" in place | `Pages/parts/CreatableSelect.vue` (RemoteSelect + `QuickCreateModal.vue`) | a separate "add" page or modal per form |
| An endpoint shared by the create and edit forms | `->middleware(AuthorizeAnyPermission::class.':X Create,X Update')` (`Modules\Accounting\Http\Middleware`) | leaving it without permission, or two copies of the route |
| Inventory documents (GRN, delivery note, transfer, adjustment) list/meta/lines/actions | `Modules/Accounting/resources/js/Pages/Inventory/parts/` (`DocumentIndex`, `DocumentMeta`, `LinesTable`, `DocumentActions` + `useDocumentActions`, `SearchPicker`) | a list or action bar per document |
| Receipt / payment voucher pages | `Pages/parts/voucher/` (`VoucherIndex`, `VoucherAdd`, `VoucherEdit`, `VoucherFields`) | copying a voucher page |
| A permission's group and Arabic label | `Modules\Core\Support\PermissionLabels` (`groups`, `summary`, `label`) | word lists inside a page |
| Follow a notification's link | `NotificationService::safeUrl()` | another `str_starts_with('/')` check |
| Panel color and font | `Modules\Core\Support\DashboardTheme` (settings `dashboard_color`, `dashboard_font`) | hard-coded brand colors |
| Feedback after an action | `->with('success'|'error', __('...'))` → `Toasts.vue`; confirm with `confirm()` from `@/lib/confirm` | `alert()` / `window.confirm()` |
| Texts in Vue | `t('English key')` from `@/lib/i18n`, Arabic in the module `Lang/ar.json` | Arabic text in a `.vue` file |

## 11. Adding a new global piece

1. Put it in `Modules/Core` (or the owning module's `Support/`, `Concerns/`, `resources/views/components/`) — not inside one page.
2. Give it ONE entry point (accessor, static helper, Blade component, Filament class).
3. Replace existing duplicates in the same change if the user asked for system-wide consistency.
4. Document it in this file.

---
name: livewire-views
description: LEGACY (being moved to inertia-views). Livewire page + Blade view + route + sidebar conventions for crownsystem modules (Livewire v3 classic, Filament v3 tables/forms inside Livewire, Tailwind 3, no Flux, no Volt). Use whenever creating or editing a page, listing table, form, modal, Blade view/component, PDF, route file, or sidebar entry.
---

# Livewire pages & views — crownsystem

Stack: Laravel 12, nwidart modules, **Livewire v3 class + Blade** (no Volt, no Flux), Filament v3 *tables/forms/actions only* (no Filament panels/resources), Tailwind 3 + Alpine, icons `solar-*-bold-duotone` via `@svg()`, toasts via `Masmerise\Toaster\Toaster`, PDF via mPDF (`Modules\Core\Services\PdfService`).

Reference implementation (newest, cleanest): **Accounting → Inventory** (`Modules/Accounting/Livewire/Pages/Inventory/*`). Copy its shape.

## 1. Where files go (one section = one mirror)

```
Modules/<Module>/
├── Livewire/Pages/<Section>/Index.php            # list (Filament table)
├── Livewire/Pages/<Section>/Form.php             # ONE component for add + edit: mount(?Model $record = null)
├── Livewire/Concerns/<Shared>.php                # traits shared by several pages of the module
├── resources/views/livewire/pages/<section-kebab>/index.blade.php
├── resources/views/livewire/pages/<section-kebab>/form.blade.php
├── resources/views/livewire/pages/<section-kebab>/partials/*.blade.php
├── resources/views/components/<area>/*.blade.php # anonymous components → <x-<module>::<area>.<name>>
├── resources/views/pdf/<area>/*.blade.php
├── Http/Controllers/<Area>PrintController.php    # thin; delegates to Services/<Area>PdfService
├── routes/web.php  (or routes/<area>.php for a big area, loaded by its own ServiceProvider)
└── Providers/SidebarServiceProvider.php (or the area's own ServiceProvider)
```

- Class path ↔ view path mirror exactly: `Livewire/Pages/Inventory/GoodsReceipt/Form.php` → `<module>::livewire.pages.inventory.goods-receipt.form`.
- A view belongs to its own component. Never render another section's view (legacy bugs like `Category/Edit` rendering `users.add` must not be copied).
- New code: one `Form` for add/edit. Legacy `Add`/`Edit` pairs stay as they are unless the task is about them.
- No manual `Livewire::component()` registration — full-page components are bound by class in routes; embedded components by FQCN: `@livewire(\Modules\X\Livewire\Pages\Y\PaymentsTable::class, ['record' => $record], key('payments-'.$record->id))`.

## 2. Component shape

```php
public function render()
{
    return view('accounting::livewire.pages.inventory.goods-receipt.form', [...])
        ->layout(AppLayout::class, [
            'breadcrumbs' => [
                ['title' => __('Home'), 'url' => route('home')],
                ['title' => __('Goods Receipts'), 'url' => route('accounting.inventory.goods-receipts.index')],
                ['title' => $this->record ? $this->record->reference_no : __('Add')],
            ],
        ]);
}
```
- Layout is always `App\View\Components\AppLayout` via `->layout()` (not `#[Layout]`).
- **List page**: `implements HasForms, HasTable` + `InteractsWithForms, InteractsWithTable`; view body is just `{{ $this->table }}`. Use the Core action classes `Modules\Core\Filament\Forms\Components\{CreateAction,EditAction,DeleteAction,ViewAction}`. Several similar lists → abstract base like `Inventory/DocumentIndex.php`.
- Small lookup tables (units, tags, taxes, groups): create/edit inside Filament action modals on the Index — no separate pages.
- **Form page**: public `?Model $record`, `array $data`, `array $lines`. Each action:
  ```php
  public function post(): void
  {
      $this->authorize('Goods Receipt Post');
      $doc = $this->attempt(fn () => app(GoodsReceiptService::class)->post($this->record), __('Goods receipt posted.'));
      if ($doc) { $this->redirectRoute('accounting.inventory.goods-receipts.edit', $doc); }
  }
  ```
  Business rules & validation live in the **Service** (`Modules/<Module>/Services/*Service.php`), which throws `DomainException` (one message per line). The component never contains business logic.
- Feedback: `Toaster::success()/error()` only. Do NOT add new `LivewireAlert` or Filament `Notification::make()` usages.
- Confirmation: `wire:confirm="{{ __('...') }}"` on plain buttons; `->requiresConfirmation()` on Filament actions.
- Filters/state in URL: `#[Url]` properties.

## 3. Blade / UI rules

- Reuse components before writing markup. Existing: `x-core::button.primary`, `x-core::image`, `x-core::barcode`, `x-core::activity-log-modal`, `x-core::keyboard-table`, `x-core::forms.category-tree-select`, `x-accounting::inventory.{button,document-header,field,input,select,status-badge}`, `x-accounting::report-section`. See the `global-components` skill for the full registry (user display, status badges, money).
- Card: `rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900`.
- RTL-safe classes only: `text-start/text-end`, `ps-/pe-`, `ms-/me-`, `start-0/end-0`. Never `text-left`, `pl-`, `ml-` in new code. Always provide `dark:` variants.
- Every user-facing string goes through `__('English source')` (see `translations` skill).
- Status colours/labels come from the Enum (`getLabel()`, `getColor()`), never from an inline colour map in the view.
- Buttons that call the server: `wire:loading.attr="disabled"`.

## 4. Routes

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::group([
        'prefix' => LaravelLocalization::setLocale(),
        'middleware' => ['localeSessionRedirect', 'localizationRedirect', 'localeViewPath'],
    ], function () {
        Route::prefix('admin/<module>/<area>')->as('<module>.<area>.')->group(function () {
            Route::prefix('<sections>')->as('<sections>.')->group(function () {
                Route::get('/', Index::class)->name('index')->can('<Entity> View List');
                Route::get('/add', Form::class)->name('add')->can('<Entity> Create');
                Route::get('/{record}', Form::class)->name('edit')->can('<Entity> View List');
                Route::get('/{record}/pdf', [XPrintController::class, 'record'])->name('pdf')->can('<Entity> View List');
            });
        });
    });
});
```
- Route names are always prefixed with the module: `accounting.inventory.goods-receipts.index`. Section segment is plural kebab-case.
- FQCN imports (`use ...\Index as GoodsReceiptIndex;`), never the deprecated group `namespace`.
- PDFs go through a controller, not closures.
- Every route has `->can()` (see `permissions` skill).

## 5. Sidebar

`Modules\Core\Services\{SidebarService, SidebarGroup, SidebarItem}`:
```php
$this->app->make(SidebarService::class)->add(
    (new SidebarGroup('Warehouses & Stock', 'solar-box-bold-duotone', [], 81))
        ->add(new SidebarItem('Goods Receipts', 'solar-inbox-in-bold-duotone', ['Goods Receipt View List'], 'accounting.inventory.goods-receipts.index'))
);
```
Titles are English source strings (translated in `sidebar.blade.php` with `__()`), so add each title to `ar.json`. Item permission = the route's permission.

## 6. Big new area = its own ServiceProvider

Like `Modules/Accounting/Providers/InventoryServiceProvider.php`: registers its route file, sidebar group, console commands; listed in `module.json` `providers`. Keeps the module's main files untouched.

## 7. PDF

Child view extends `<module>::pdf.<area>.layout` (which includes `core::pdf.partials.{styles,header,footer}`, `dir="rtl"`), sets `$docTitle`, `$meta`, `$signatures`, fills `@section('items')`. Rendering via a service extending `Modules\Core\Services\PdfService` → `streamPdf($view, $data, $filename)`.

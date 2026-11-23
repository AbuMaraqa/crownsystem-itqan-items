---
name: inertia-views
description: The control panel's structure taken from malbo — Inertia 3 + Vue 3 + TypeScript + Tailwind 4 pages with Controller → Service → Repository on the server. Use whenever creating or editing an admin page, list table, form, modal, route, sidebar entry, dashboard widget or PDF.
---

# Admin pages (Inertia + Vue) — crownsystem

The panel follows malbo (`C:\laragon\www\malbo`, read only): same shell, same components, same layering.
Every admin page is written here; there is no Livewire, Filament, Jetstream or Alpine in the project.

Reference implementation: **Core** (`Modules/Core/Http/Controllers/Admin/*`, `Modules/Core/resources/js/Pages/*`).
Users (`UserController`, `Users/{Index,Add,Edit,UserFields}.vue`) is the fullest example.

## 1. Where files go

```
Modules/<M>/
├── Http/Controllers/Admin/<Entity>Controller.php   # index, add, store, edit, update, destroy (thin)
├── Http/Requests/Admin/<Entity>Request.php         # validation; authorize() returns true (route `can` decides)
├── Services/<Entity>Service.php                    # every write; DB::transaction when >1 table changes
├── Repositories/<Entity>Repository.php             # named reads + tableQuery() for the list
├── Repositories/Contracts/<Entity>RepositoryInterface.php
├── Providers/RepositoryServiceProvider.php         # $bindings interface => repository
└── resources/js/Pages/<Entity>/{Index,Add,Edit}.vue, <Entity>Fields.vue, parts/*.vue
```

- Page name `'<Module>/<Entity>/Index'` resolves to `Modules/<Module>/resources/js/Pages/<Entity>/Index.vue`
  (`resources/js/lib/pages.ts`; a new module is added to its `MODULES`).
- Controllers send explicit arrays (never a model): `rows(fn (Model $m) => [...])`.
- Repositories extend `Modules\Core\Repositories\BaseEloquentRepository`.

## 2. Routes

Inside the module's existing `mcamara` locale + `admin` group:

```php
Route::prefix('currencies')->name('currencies.')->controller(CurrencyController::class)->group(function () {
    Route::get('/', 'index')->name('index')->can('Currency View List');
    Route::get('/add', 'add')->name('add')->can('Currency Create');
    Route::post('/', 'store')->name('store')->can('Currency Create');
    Route::get('/{currency}/edit', 'edit')->whereNumber('currency')->name('edit')->can('Currency Update');
    Route::put('/{currency}', 'update')->whereNumber('currency')->name('update')->can('Currency Update');
    Route::delete('/{currency}', 'destroy')->whereNumber('currency')->name('destroy')->can('Currency Delete');
});
```

Every route has `->can(...)`; the Vue page hides what `can('...')` (from `@/lib/i18n`) denies.

## 3. Lists

Server: `Modules\Core\Support\AdminTable::for($repo->tableQuery())->search([...])->filters(...)->sorts(...)->rows(fn)`,
filters from `Modules\Core\Support\AdminFilter` (exact, boolean, text, relation, dateRange, numberRange).
Every table has the same row actions in one ⋮ menu (`RecordActions`: Activity Log · View · Edit · extras · Delete + a `<routes>.info` route), the shared
«Created By» column (`->withCreator()` + `createdByColumn()`) when the model has `createdBy()`, and setup tables
(lookups, not documents) are drag-and-drop ordered (`->reorderable()` + `:reorder-url`) — see `global-components` §9.
Every table the user may delete from has bulk delete (`DeletesManyRecords` + `destroy-many` route + `:bulk-delete-url`),
and wrong link input never breaks a list: `AdminTable` falls back to the default and the table shows why (`warnings`).
Client: `<DataTable :table :columns :filters>` from `@/Components/table`, with a non-empty `filters` list,
`#cell-<key>`, `#actions` (`RecordActions`), `:bulk-delete-url`, `#bulk` only for other bulk actions (e.g. mark as read).
Success / partial / failure of an action: `back()->with('success'|'warning'|'error', ...)`.

## 4. Forms

`useForm` + `<form @submit.prevent="form.post(route('x.store'))">`, fields from `@/Components/form`
(TextInput, SelectInput, MultiSelect `single`, TextareaInput, Toggle, ImageUpload…), `FormActions` at the end.
Add and Edit share one `<Entity>Fields.vue`. Files go with `forceFormData: true` and `_method: 'put'`.

## 5. Results and errors

- Success / failure: `back()->with('success'|'error', __('...'))` → toast (`Components/ui/Toasts.vue`).
- A record that other records use: `Modules\Core\Support\SafeDelete::attempt(fn () => ...)` in the Service,
  and `back()->with('error', SafeDelete::message())` in the controller.
- Business rule refused: `DomainException` (message shown) or `ValidationException::withMessages([...])`.

## 6. Look

Shell: `resources/js/Layouts/AdminLayout.vue` (`defineOptions({ layout: AdminLayout })`) and `PageHeader`.
Design tokens in `resources/css/admin.css` and `panel.css`: `ink`, `muted`, `line`, `surface`, `card`, `canvas`,
`brand-*` (the panel color from Settings). Cards `rounded-xl border border-line bg-card shadow-card`.
Sizes are tokens too: sidebar 280px (`w-sidebar`), header 70px (`h-header`), page `max-w-page` + `px-gutter`,
fields and buttons `h-control` (34px) with `rounded-control` (6px). Never hard-code `h-10`/`h-9` on a field.
RTL: logical utilities only (`ms-`, `pe-`, `start-`, `text-start`). Dark mode: `dark:` or the tokens.
Icons: Solar through `resources/js/lib/icons.ts`; sidebar icons are the providers' `solar-*` names,
imported in `Components/layout/navIcons.ts`. Heavy libraries (FullCalendar, ApexCharts, Tiptap, Leaflet)
load with `defineAsyncComponent`.

## 7. Texts

`t('English key')` in Vue, `__('English key')` in PHP; Arabic (MSA) in the module `Lang/ar.json`
(see the `translations` skill). The shell's texts live in `Modules/Core/Lang/ar.json`.

## 8. Sidebar

`Modules\Core\Services\{SidebarService, SidebarGroup, SidebarItem}`, registered in the module's
`Providers/SidebarServiceProvider.php` (or the area's own ServiceProvider, e.g. `InventoryServiceProvider`):

```php
$this->app->make(SidebarService::class)->add(
    (new SidebarGroup('Warehouses & Stock', 'solar-box-bold-duotone', [], 81))
        ->add(new SidebarItem('Goods Receipts', 'solar-inbox-in-bold-duotone', ['Goods Receipt View List'], 'accounting.inventory.goods-receipts.index'))
);
```

Shared to Vue by `HandleInertiaRequests` (`sidebar`). Titles are English keys translated with `t()`, so add each
to `ar.json`; add a new icon name to `Components/layout/navIcons.ts` (unknown names show `fallbackNavIcon`). Item permission = the route's permission.

## 9. PDF

PDFs stay Blade + mPDF (no Vue):

- View in `Modules/<M>/resources/views/pdf/`, `dir="rtl"`, using `core::pdf.partials.{styles,header,footer}`.
  Documents of one area share a layout: the child view extends `<module>::pdf.<area>.layout`
  (e.g. `accounting::pdf.inventory.layout`), sets `$docTitle`, `$meta`, `$signatures` and fills `@section('items')`.
- Rendered by a service extending `Modules\Core\Services\PdfService` (`AccountingPdfService`, `InventoryPdfService`,
  `ItemsPdfService`) → `streamPdf($view, $data, $filename)`.
- Routed through a controller, never a closure, with `->can('<Entity> View List')` (or `View`). Use an array action
  (`[GoodsReceiptController::class, 'pdf']`, `[AccountingPrintController::class, 'receiptVoucher']`); never a bare
  `'pdf'` string action inside a `->controller()` group, because `PDF` is a class alias (laravel-mpdf facade).

## 10. Build and tests

- `npm run dev` / `npm run build` (`vite.admin.config.ts`, Tailwind 4, `public/build-admin`). Type check: `npm run typecheck`.
- Tests: feature tests with `assertInertia`; skip the locale redirect with
  `$this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class])`.

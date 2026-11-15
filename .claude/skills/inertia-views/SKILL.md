---
name: inertia-views
description: The control panel's structure taken from malbo — Inertia 3 + Vue 3 + TypeScript + Tailwind 4 pages with Controller → Service → Repository on the server. Use whenever creating, moving or editing an admin page, list table, form, modal, route, sidebar entry or dashboard widget. Livewire pages are legacy and are being moved here module by module.
---

# Admin pages (Inertia + Vue) — crownsystem

The panel follows malbo (`C:\laragon\www\malbo`, read only): same shell, same components, same layering.
New pages are written here. A Livewire page is moved here when its section is touched; it keeps its
route names, so links and permissions do not change.

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
Client: `<DataTable :table :columns :filters>` from `@/Components/table`, with a non-empty `filters` list,
`#cell-<key>`, `#actions` (`RowMenu` + `DropdownItem`), `#bulk`.

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
RTL: logical utilities only (`ms-`, `pe-`, `start-`, `text-start`). Dark mode: `dark:` or the tokens.
Icons: Solar through `resources/js/lib/icons.ts`; sidebar icons are the providers' `solar-*` names,
imported in `Components/layout/navIcons.ts`. Heavy libraries (FullCalendar, ApexCharts, Tiptap, Leaflet)
load with `defineAsyncComponent`.

## 7. Texts

`t('English key')` in Vue, `__('English key')` in PHP; Arabic (MSA) in the module `Lang/ar.json`
(see the `translations` skill). The shell's texts live in `Modules/Core/Lang/ar.json`.

## 8. Build and tests

- Separate build: `npm run dev:admin` / `npm run build:admin` (`vite.admin.config.ts`, Tailwind 4, `public/build-admin`).
  Livewire pages keep `npm run dev` (Tailwind 3). Type check: `npm run typecheck`.
- Tests: feature tests with `assertInertia`; skip the locale redirect with
  `$this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class])`.
- An Inertia link to a Livewire page that is not moved yet turns into a full load
  (`App\Http\Middleware\LeaveInertiaForLegacyPages`).

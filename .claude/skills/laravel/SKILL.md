---
name: laravel
description: Backend structure conventions for crownsystem (nwidart modules) — where code goes inside a module, Entities/models, enums, migrations & table naming, services & business logic, seeders, tests. Use whenever creating or modifying a model, migration, enum, service, support class, seeder, or adding a new module area.
---

# Backend structure — crownsystem

Laravel 12 + `nwidart/laravel-modules`. Active modules: **Core** (users, roles, settings, shared UI/services), **Items** (products, categories, orders), **Accounting** (accounts, sales/purchase docs, inventory, production), **Branch**, **GeoLocation**. There is no `app/Models`.

Companion skills: `permissions`, `livewire-views`, `global-components`, `translations`.

## 1. Module layout — put each thing in its folder

```
Modules/<Module>/
├── Entities/                 models (namespace Modules\<Module>\Entities)
├── Entities/Concerns/        model traits shared by several models (IsInventoryDocument…)
├── Enums/                    backed enums (HasLabel + HasColor)
├── Services/                 business logic, one service per document/process; Services/Concerns/ for shared parts
├── Support/                  small static helpers, registries (Number, QuickCreate, <Area>Permissions)
├── Livewire/Pages/<Section>/ pages          (see livewire-views)
├── Livewire/Concerns/        Livewire traits
├── Http/Controllers/         thin controllers (PDF/print/API only)
├── Transformers/             API resources
├── Console/Commands/
├── Providers/                <Module>ServiceProvider, RouteServiceProvider, SidebarServiceProvider, <Area>ServiceProvider
├── config/config.php         table_prefix, module settings
├── database/{migrations,seeders,factories}
├── Lang/ar.json
├── resources/views/
├── routes/{web.php,api.php,<area>.php}
└── tests/{Feature,Unit}
```
Which module? Users/roles/settings/shared UI → Core. Products/catalog → Items. Anything with money, stock, documents, parties → Accounting. Don't create a new module unless asked.

## 2. Models (Entities)

Copy `Modules/Accounting/Entities/CustomerGroup.php`:
```php
class CustomerGroup extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [...];               // always $fillable, never $guarded
    protected $casts = [                        // property style
        'profit_margin' => 'decimal:4',
        'is_active' => 'boolean',
        'status' => CustomerGroupStatus::class, // enums cast directly
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('accounting.table_prefix').'customer_groups');
    }

    public function getActivitylogOptions(): LogOptions { /* the standard block, copy as-is */ }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```
- Table prefix from module config: `accounting_`, `items_`, `branch_`, `geolocation_`. Core tables (`users`, `currencies`…) have no prefix.
- Relationships: always declare the return type and pass the foreign key explicitly. Any person/company = `belongsTo(User::class, '<fk>')` (see `global-components` §1).
- Short Arabic docblock on the class and on non-obvious methods explaining the business rule.
- `static options(): array` on lookup models for select lists (active only, ordered).
- Scopes: `scopeActive(Builder $query): Builder`.
- Translatable names: `Astrotomic\Translatable` with a `<Model>Translation` model + `<table>_translations` table (`locale`, `name`, unique `[fk, locale]`) — only where the entity already is translatable or the user asks; new accounting lookups use a plain `name`.
- Media: Spatie Media Library (`InteractsWithMedia`, named collections). Read media through the model's accessor (`avatar_url`, `image`).
- Several models sharing columns/relations/behaviour → a trait in `Entities/Concerns/` with `initialize<Trait>()` + `mergeCasts()` (see `IsInventoryDocument`).
- Accessors that produce display output (`user_display_html`, `avatar_url`, `image`) are the single source of truth; use them everywhere.

## 3. Enums

```php
enum SalesInvoiceStatus: int implements HasLabel, HasColor
{
    case Draft = 0; case Posted = 1; case Cancelled = 2;
    public function getLabel(): string { return match ($this) { self::Draft => __('Draft'), ... }; }
    public function getColor(): string { return match ($this) { self::Draft => 'gray', ... }; }
    public static function options(): array { /* value => label */ }
}
```
Status columns: `unsignedTinyInteger('status')->default(Enum::Draft->value)`.

## 4. Migrations

Copy `Modules/Accounting/database/migrations/2026_10_15_000030_create_accounting_customer_groups_table.php`:
- In the module's `database/migrations`, filename date-ordered after the latest one in that module.
- `$tableName = config('<module>.table_prefix').'<plural_snake>';` and guard with `Schema::hasTable` / `Schema::hasColumn` (migrations must be re-runnable on existing DBs).
- Standard columns: `id`, business columns, `notes` (text nullable), `is_active` (bool default true), `sort_order` when ordered, `branch_id` when branch-scoped, `reference_no` (unique) for documents, `created_by` (nullable FK users nullOnDelete), `posted_by/posted_at/cancelled_by/cancelled_at` for postable documents, `softDeletes()`, `timestamps()`.
- Decimals: money & quantity `(18,4)`, rate/percentage `(8,4)`, exchange rate `(18,8)`, unit/average cost `(20,6)`.
- FKs & indexes get short explicit names (`acct_<table>_<col>_fk`, `_idx`) — MySQL 64-char limit.
- `nullOnDelete` for optional refs, `cascadeOnDelete` for child lines, `restrictOnDelete` for currency/accounts.
- Comment at top (Arabic) explaining why the table exists.
- Never edit a migration that may have run; add a new one. Never drop tables/columns unless asked.
- New permissions → a separate migration calling `<Area>Permissions::sync()` (see `permissions`).

## 5. Business logic

- Lives in `Services/<Thing>Service.php` (resolved with `app()`), wrapped in `DB::transaction`. Livewire components only collect input, authorize, call the service, show the toast.
- Rule violations: `throw new DomainException(__('...'))` (multiple messages joined with "\n"); the page shows them via `attempt()` (`HandlesInventoryDocument`).
- Journal entries / stock movements only through the existing services (`StockLedgerService`, `PostsInventoryJournal`, `AccountService`). Never write ledger rows directly.
- Raw SQL imports on products → rebuild the search index (see memory: items-search-index).

## 6. Seeders & setup

`Modules/<M>/database/seeders/<M>DatabaseSeeder.php` → `PermissionSeeder` etc. Setup data that must exist after deploy (accounts, permissions) goes in a migration or an idempotent console command, not only in a seeder.

## 7. Tests

`Modules/<M>/tests/Feature/*Test.php`; run with `vendor/bin/phpunit -c phpunit.mysql.xml` (sqlite does not work).

## 8. Before finishing

- `php -l` on every changed PHP file.
- New strings in `Lang/ar.json` (Modern Standard Arabic).
- Permissions, routes, sidebar consistent (`permissions` checklist).
- Reused global pieces instead of duplicating (`global-components`).

---
name: permissions
description: Roles & permissions conventions for crownsystem (Spatie Permission, no policies, no Gate::before). Use whenever adding a page, route, sidebar item, Livewire action, Filament table action, button, or any feature that must be authorized, and whenever creating/seeding permissions or roles.
---

# Roles & Permissions — crownsystem

Spatie Permission, guard `web`, wildcard OFF, no Policies, **no `Gate::before`**.
Super Admin works ONLY because every permission is explicitly granted to the `Super Admin` role. A new permission that is not granted to Super Admin = nobody can see the page.

## 1. Naming (mandatory)

Permission name = `"<Entity> <Action>"` — English, Title Case, singular entity, spaces, no dots, no module prefix.
Group (`permissions.module_name` column) = `"<Entity> Management"`.

| Action | Use for |
|---|---|
| `View List` | index page, sidebar item, read-only viewer of a document |
| `View` | single record / PDF when it differs from the list |
| `Create` / `Update` / `Delete` | CRUD |
| Workflow verbs | `Post`, `Cancel`, `Void`, `Confirm`, `Approve`, `Receive`, `Invoice`, `Prepare`, `Clear`, `Bounce`, `Close`, `Reopen`, `Export`, `Print`, `Start`, `Complete`, `Manage` |
| Field-level | `"<Entity> Value View"` style (e.g. `Stock Value View` hides cost columns) |

Do NOT invent `Info`, `View Details`, `Details List` for new code (legacy only). Reuse an existing action word before inventing one.

## 2. Defining new permissions — registry class + migration (newest style)

Reference: `Modules/Accounting/Support/InventoryPermissions.php` + `Modules/Accounting/database/migrations/2026_10_15_100005_create_inventory_permissions.php`.

1. Registry class `Modules/<Module>/Support/<Area>Permissions.php`:
   ```php
   class ProductionPermissions
   {
       public const GROUPS = [
           'Production Order Management' => [
               'Production Order View List',
               'Production Order Create',
               'Production Order Update',
               'Production Order Delete',
           ],
       ];

       public static function sync(): void { /* copy InventoryPermissions::sync() body exactly */ }
   }
   ```
   `sync()` must: guard on `Schema::hasTable('permissions')`, forget the permission cache before and after, `Permission::firstOrCreate(['name'=>…, 'guard_name'=>'web'], ['module_name'=>…])` (only when the `module_name` column exists), and `givePermissionTo` the `Super Admin` role.
2. A migration whose `up()` calls `<Area>Permissions::sync();` so the permissions exist right after deploy (no seeding needed).
3. Call the same `sync()` from the module's `database/seeders/PermissionSeeder.php` so fresh installs get them too. Never duplicate the list by hand.

Never edit an already-run permissions migration — add a new one that calls `sync()` again.

## 3. Roles

- Roles are Spatie role names. System roles live in `Modules/Core/Enums/UserRole.php` (`Super Admin`, `User`, `Customer`) — use `UserRole::SUPER_ADMIN->value`, not the raw string.
- Business-party roles (`Supplier`, `Shipping Company`, `Insurance Company`, `Clearance Company`, `Delivery Company`) are created by migrations / `QuickCreate::party()`.
- To query parties use the User scopes (`User::customerRole()`, `User::supplierRole()`); add a new scope on `Modules/Core/Entities/User.php` instead of writing `whereHas('roles', …)` inline.
- **Never** authorize by role (`hasRole`) — authorize by permission. The only accepted role check is the Super Admin module selector.

## 4. Enforcement — all layers, every time

Frontend hiding is not security. A feature is authorized only when ALL applicable layers exist:

| Layer | Pattern |
|---|---|
| Route | `Route::get('/', Index::class)->name('index')->can('X View List');` — every route, no exceptions. `add` → `Create`, `edit` → `Update` (or `View List` when one Form is also the read-only viewer), `pdf` → `View`/`View List`. Never `middleware('permission:…')`. |
| Livewire action | first line of every mutating method: `$this->authorize('X Post');` (or `$this->authorize($this->record ? 'X Update' : 'X Create');`). Do NOT use `abort_unless(auth()->user()->can(...))` in new code. |
| Filament table action / column | `->visible(fn () => auth()->user()->can('X Delete'))` |
| Blade button | `@can('X Post') … @endcan` |
| Sidebar | `new SidebarItem('Title', 'solar-…-bold-duotone', ['X View List'], 'module.section.index')` — the SAME permission as the route. |

Do not authorize one entity with another entity's permission (e.g. `Sales Order Update` on a production page) unless the action really mutates that other entity.

## 5. Roles UI must know the new names

`Modules/Core/Livewire/Pages/Roles/Index.php` groups and translates permissions with hard-coded maps. When adding a new entity or action word, update in that file:
- `splitPermissionName()` → add any new action suffix (longer suffixes before shorter ones).
- action sort map → give the new action an order.
- `translateModuleName()` → `'<Entity>' => '<Arabic plural>'`.
- `translatePermissionAction()` → `'<Action>' => '<Arabic verb noun>'`.

Also add the full permission names and the `"<Entity> Management"` group to the module's `Lang/ar.json` (Modern Standard Arabic — see the `translations` skill).

## 6. Checklist for a new feature

- [ ] Names follow `<Entity> <Action>`; group is `<Entity> Management`.
- [ ] Added to the module registry class `GROUPS`; migration calls `sync()`; seeder calls `sync()`.
- [ ] Super Admin receives them (via `sync()`).
- [ ] Every route has `->can()`.
- [ ] Every mutating Livewire method starts with `$this->authorize()`.
- [ ] Buttons/actions hidden with `@can` / `->visible()`.
- [ ] Sidebar item uses the route's permission.
- [ ] Roles UI maps + `ar.json` updated.

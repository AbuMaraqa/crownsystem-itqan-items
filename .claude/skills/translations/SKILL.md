---
name: translations
description: Translation system of crownsystem — JSON keys with English source strings, per-module Lang/ar.json, Modern Standard Arabic values, RTL. Use whenever adding or changing any user-facing string (labels, headings, toasts, validation, sidebar titles, permission names, enum labels, PDFs).
---

# Translations — crownsystem

Locales: `ar` (default, RTL) and `en` (mcamara/laravel-localization). English is the key; there is NO `en.json`.

## Rules

1. Every user-facing string: `__('English source text')` in PHP/Blade, `t('English source text')` (from `@/lib/i18n`) in Vue. No namespaced keys (`__('accounting::x')` is not used), no PHP array lang files for new strings, no hard-coded Arabic in PHP/Blade/Vue.
2. Add the Arabic value to the owning module's JSON file:
   - `Modules/<Module>/Lang/ar.json` (loaded by `loadJsonTranslationsFrom` in the module ServiceProvider).
   - Root `lang/ar.json` only for auth/framework strings.
   - Keep keys unique across modules — search all `ar.json` files for the key first and reuse an existing key instead of adding a near-duplicate ("Client" vs "Customer" vs "Customer Name").
   - Keep the file valid JSON (no trailing comma). Insert near related keys.
3. Arabic values MUST be Modern Standard Arabic (follow the `arabic-fusha` skill): concise UI labels, correct hamza/taa marbuta, no dialect ("اللي", "بدك", "شو" …).
4. Also translate: sidebar titles (rendered with `t()`), enum `getLabel()` strings, toast messages, `confirm()` dialog texts, DomainException messages, table headings/empty states, PDF labels, the `"<Entity> Management"` group and every permission name.
5. Permission names in the Roles screen are translated by the maps in `Modules/Core/Support/PermissionLabels.php` (`AR_MODULES`, `AR_ACTIONS`) — update them for new entities/actions (see `permissions`).
6. Placeholders: `__('Posted :count lines', ['count' => $n])` — keep `:name` placeholders identical in the Arabic value.
7. RTL: use logical Tailwind classes (`text-start`, `ps-`, `ms-`, `start-0`); wrap emails/phones/codes in `<bdi>` inside Arabic text.

## Verify

```bash
php -r "json_decode(file_get_contents('Modules/Accounting/Lang/ar.json')); echo json_last_error_msg();"
```
Then grep the new keys in the code to make sure each `__('...')` you added exists in `ar.json`.

# Project Rules

## Arabic Language

All generated Arabic user-facing content MUST use Modern Standard Arabic (العربية الفصحى).

Never generate Arabic dialect unless the user explicitly requests a dialect.

For Arabic translations:
- Use professional Modern Standard Arabic.
- Preserve the meaning rather than translating word-for-word.
- Use terminology appropriate for software applications.
- Keep UI labels concise.

## Project structure skills (load before writing code)

The whole system follows ONE structure. Before creating or changing code, load the matching skill and follow it:

- `laravel` — module layout, models/Entities, enums, migrations, services.
- `inertia-views` — the control panel taken from malbo: Inertia + Vue pages, Controller → Service → Repository, routes, tables, forms, dashboard, PDFs. **All admin pages go here.**
- `permissions` — permission naming, registry + migration, enforcement on every layer.
- `global-components` — shared pieces that must be reused (e.g. users are always shown with `<UserCell>` + `UserCellData::of($user)` in Vue, `$user->user_display_html` in Blade/PDF).
- `translations` — `__('English')` keys + `Lang/ar.json` in Modern Standard Arabic.

When something is shown or computed in more than one place, it has one source; change it there, never copy it. A new shared piece gets documented in `global-components`.
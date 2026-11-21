# Admin restyle in the Metronic Demo 1 style: audit

Date: 2026-11-19

## Decision

- There is no Metronic license or package in this repository, so nothing from Metronic is copied: no markup, CSS, KeenIcons or images.
  The public demo was used only to measure sizes, spacing and colors. The design is our own, built in the same style.
- The goal is to restyle the existing ERP panel. Demo 1's sample pages (public profile, teams, projects, network) are not added,
  because they would be pages with fake data in a business application.

## Architecture (unchanged)

- Laravel 12 with nwidart modules (`Core`, `Accounting`, `Items`, `Branch`, `GeoLocation`).
- Inertia 3, Vue 3, TypeScript, Tailwind 4, Reka UI, Solar icons through `unplugin-icons`. Vite config: `vite.admin.config.ts`.
- 348 Vue pages. They all use one shell (`resources/js/Layouts/AdminLayout.vue`) and shared primitives:
  `buttonClasses`, `controlClasses`, `Card`, `PageHeader`, `DataTable`, `Field`.
- Tokens: `resources/css/admin.css` (scales, shadows, sizes) and `resources/css/panel.css` (semantic colors, light and dark).
- The brand color and font are settings (`Modules\Core\Support\DashboardTheme`). The restyle leaves them alone.
- Auth: Fortify-style routes posted from `resources/js/Pages/Auth/*` under `GuestLayout`. The backend is not changed.
- Error pages: Blade, `resources/views/errors/*` (self-contained CSS).

## What already matched Demo 1

A light sidebar with a collapse toggle and a mobile drawer, a sticky 70px header, a search dialog (Ctrl+K), a notifications sheet,
a user menu with language and dark mode, breadcrumbs in the header, bordered cards with a 56px header, tables with filters, column menu and pagination.

## What was changed

| Area | Before | After (measured on the reference) |
|---|---|---|
| Gray scale | cool blue-gray | neutral zinc; text 500/600 stay a step darker (owner's request, 2026-11-17) |
| Ink / muted / line | #101828 / #475467 / #e4e7ec | #09090b / #52525b / #eaeaed |
| Sidebar | 270px, collapsed 76px, 40px items | 280px, collapsed 80px, 34px items, 18px icons, 14px/500 labels, uppercase 12px sections |
| Sub-items | 36px, gray dots | 34px, 13px, active one semibold accent with a dot and a tint |
| Content box | max 1440px, 32px gutter | max 1280px (`max-w-page`), 30px gutter (`px-gutter`), header aligned to it |
| Page title | 20px/600 | 20px/500, 20px under the header, 30px above the content |
| Fields and buttons | 40px, 8px radius, 14px text | 34px (`h-control`), 6px radius (`rounded-control`), 13px text |
| Small buttons | 32px | 28px (`h-control-sm`) |
| Card shadow | 0 3px 4px / 3% | 0 1px 2px / 5% |
| Field labels | 14px/500 gray-800 | 14px/400 in the text color |
| Sign-in | split screen, colored brand half | 370px form card + tinted brand panel with an outline of the app (stacks on phones) |
| Error pages | Laravel's stock page | large code in the brand color, message, "Back to home" button |

## New shared pieces

- `Components/form/PasswordInput.vue`: password field with a show/hide button (LTR in both languages).
- `Components/form/Checkbox.vue`: checkbox with its label.
- `Components/ui/Alert.vue`: message box with info/success/warning/danger tones.
- `Components/auth/AuthHeading.vue`: title and line under it on the sign-in screens.
- `Field.vue` slot `#aside`: a link on the label's line ("Forgot password?").
- Tokens: `h-control`, `h-control-sm`, `size-control`, `rounded-control`, `max-w-page`, `px-gutter`.

All are listed in `.claude/skills/global-components/SKILL.md`.

## Conflicts and limits

- Sixteen module pages set their own 36px or 40px field heights. They were switched to `h-control` so a row of fields keeps one height.
- KeenIcons are licensed, so the panel keeps the Solar icon set already in use.
- Demo 1's social sign-in and "Sign up" link are left out: the app has no social login and no self-registration, and buttons that do nothing are not added.

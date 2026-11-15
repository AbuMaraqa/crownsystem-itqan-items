# Keyboard workflow (audit of 2026-10-28)

Written for developers adding or changing document screens, and for trainers explaining fast entry to accounting staff.

## What already existed before the audit

| Where | Behaviour | Implementation |
|---|---|---|
| Item grid of every sales/purchase document (`partials/order-items-grid.blade.php`) | Type in the item cell to search; ↑/↓ choose a result, Esc closes the list, Enter picks it and moves on; in number cells Enter moves to the next cell, ↑/↓ move to the same cell of the previous/next row, Ctrl+Delete removes the row; sub-item rows have the same handlers | Alpine `onProductKey`, `onCellKey`, `onPieceProductKey`, `onPieceCellKey` |
| Generic editable tables (`x-core::keyboard-table`) | Enter moves to the next cell, Enter on the last cell of the last row appends a row; computed columns update live | Alpine `keyboardTable` |
| Inventory product picker, item card, PO picker, items tree | Arrow keys and Enter in search results | Alpine handlers in each view |
| Filament selects | Searchable, keyboard-operable selects (customer, supplier, warehouse, currency, tax) | Filament |
| Every server button in `x-accounting::inventory.button` | `wire:loading.attr="disabled"` blocks a second click while the request runs | component |

Tab / Shift+Tab follow DOM order on every form; the forms are laid out header → lines → totals so the natural order is correct.

## Added by the audit: document shortcuts

Every screen that uses the shared `x-accounting::inventory.document-header` now has:

| Keys | Action | Notes |
|---|---|---|
| **Ctrl+S** (⌘S on macOS) | Save the draft | Only when a visible, enabled `wire:click="save"` button exists in the same Livewire component — so nothing happens on a posted document or for a user without the update permission, and the browser's own Ctrl+S is left alone there. |
| **Ctrl+Enter** (⌘Enter) | Post the document | Clicks the real Post button, so its `wire:confirm` dialog still appears; a posting is never one keystroke away from happening silently. |

Behaviour details:

- The focused field is blurred first, so a value typed but not yet committed is sent with the save.
- Shortcuts with Alt or Shift held, or while an IME composition is in progress, are ignored.
- A key a field already handled (`preventDefault`) is not reinterpreted: inside an item-grid cell Enter belongs to the grid, so Ctrl+Enter there keeps moving to the next cell; press it from any header or totals field to post.
- The header shows a small hint (`Ctrl+S Save · Ctrl+Enter Post`) listing only the shortcuts that are currently available; it is refreshed after each Livewire round-trip (for example after posting, the hints disappear). The buttons get `aria-keyshortcuts` for screen readers.
- Screens covered: goods receipt, delivery note, stock adjustment, stock transfer, purchase quotation, purchase invoice, sales invoice, receipt voucher, payment voucher.

## Rules for new screens

1. Use `x-accounting::inventory.document-header` for any postable document and name the actions `save` and `post`; the shortcuts then work with no extra code.
2. Do not bind single letters or unmodified keys globally — the grids use Enter and arrows inside cells.
3. Financially significant actions (post, cancel) keep `wire:confirm`; shortcuts trigger the same button, never the method directly.

## Not done (recorded for follow-up)

- Legacy `Add`/`Edit` pages that do not use the shared header (sales order, sales quotation, purchase order, journal entry) do not get the shortcuts yet. Moving them to the shared header is the cleanest way to add them.
- No "new document" shortcut: it would need a per-screen route and conflicts with the browser's Ctrl+N.

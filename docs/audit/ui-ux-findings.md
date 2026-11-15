# UI/UX findings (audit of 2026-10-28)

Written for the developers who own the Livewire screens. The audit judged the screens against the project's own design system (the `livewire-views` skill and the shared `x-accounting::inventory.*` components) and the UI UX Pro Max checklist (accessibility, interaction, consistency). The skill's design-system generator could not run because Python is not installed on this machine; its rule checklist was applied by hand.

**Verification level.** Changes were verified by Livewire feature tests (rendering, actions, permissions), by compiling the CSS (`npm run build`), and in a real Chrome session (Arabic, RTL, 1440×900) against the scratch database `crownsystem_preview`, driven by `playwright-core`:

- Draft sales invoice: shared header, enum badge, `Ctrl+S` / `Ctrl+Enter` hints, buttons carry `aria-keyshortcuts`; Tab shows the orange focus ring on *Save Draft*; Ctrl+S from a header field sent the `save` request; Ctrl+Enter opened the Arabic post confirmation (dismissed — nothing posted).
- Posted sales invoice: no shortcut hints (nothing to save or post), *Cancel Invoice* with reason field; cancelling from the screen with a reason produced the red *ملغي* badge, the dated banner with the reason, and the success toast.
- Receipt voucher paid in cash: *Cancel Voucher* shown. Voucher with a cheque under collection: no cancel button.
- **Not verified:** dark mode. Toggling the `dark` class by hand left the content background light (the theme switcher does more than that class), so the dark screenshot was not representative. Use the theme toggle for that check.

## Existing design system (kept)

Desktop-first Tailwind + Filament forms, RTL-safe utilities, dark mode on every component, enum-driven status badges, compact document headers with actions on the right, item grids with keyboard entry, toasts for feedback. This fits a daily-use accounting tool and was not replaced.

## Findings and what was done

| ID | Screen | Finding | Done |
|---|---|---|---|
| U-01 | Sales invoice | Older header style: status colour hard-coded in the view (green/grey only, so *Cancelled* looked like *Draft*); Post had no confirmation; Save was a separate *Update* button under the totals | Moved to the shared `document-header`: enum colour/label badge, *Save Draft*, *Post* with confirmation, client/date/order shown in the header |
| U-02 | Sales invoice | No way to cancel a posted invoice | *Cancel Invoice* with optional reason and confirmation (permission `Sales Invoice Cancel`); red *cancelled on … — reason* banner |
| U-03 | Receipt / payment voucher | Same hard-coded header; no confirmation on Post; no cancel although the permissions existed | Shared header (with amount in the meta line), Post confirmation, *Cancel Voucher* with reason — hidden while the voucher's cheque is still under collection (that cancel belongs to the cheques register); cancelled banner |
| U-04 | Seven grid-based document pages | A validation error or permission denial crashed the page (see A-13) instead of showing the field error / 403 | Fixed in the shared trait |
| U-05 | Shared document button | No explicit focus indicator for keyboard users; no pointer cursor | `focus-visible:ring-2 ring-primary ring-offset-2` (dark-mode offset), `cursor-pointer`, `disabled:cursor-not-allowed` |
| U-06 | All documents with the shared header | No document-level shortcuts | Ctrl+S / Ctrl+Enter with on-screen hints — see `keyboard-workflow.md` |
| U-07 | Shortcut hint text | First draft used `text-gray-400` (fails 4.5:1 contrast on white) | Changed to `text-gray-500` / dark `text-gray-400` |

## Found, not changed (follow-up)

| ID | Screen | Finding | Suggested fix |
|---|---|---|---|
| U-08 | Sales order, sales quotation, purchase order, journal entry | Legacy `Add`/`Edit` pages still use their own header markup, so they lack the shared badge, confirmations and shortcuts | Move each to `x-accounting::inventory.document-header` (same change as U-01) |
| U-09 | Totals panels of sales invoice | Read-only totals use `text-right` and `rounded-md`, unlike the purchase invoice (`text-end`, `rounded-xl`) | Align with the purchase invoice partial; ideally extract one shared totals partial so both read from one source |
| U-10 | Allocation panel | Shows only open invoices of the same currency with no hint why an invoice in another currency is missing | Add a one-line note under the table |
| U-11 | Reports (trial balance, aging, GL) | Not reviewed in depth in this session | Review column alignment, sticky totals row and print layout |

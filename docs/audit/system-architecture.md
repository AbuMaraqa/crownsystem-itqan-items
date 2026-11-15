# System architecture (audit of 2026-10-28)

Written for the developers who maintain crownsystem. It records what the code actually does today, as traced during the audit — not a target design.

## Stack

| Layer | What is used |
|---|---|
| Runtime | PHP 8.4, Laravel 12.50, Octane available |
| Modules | `nwidart/laravel-modules` 12 — `Core`, `Items`, `Accounting`, `Branch`, `GeoLocation` (no `app/Models`) |
| UI | Livewire 3.7 (class components + Blade), Filament 3.3 tables/forms/actions inside Livewire (no Filament panels), Tailwind 3 + Alpine, Solar/Heroicons |
| Auth | Jetstream + Sanctum, Keycloak guard for the API, Spatie Permission 6 (string permissions, no policies) |
| Database | MySQL in development and production. Tests run against a dedicated `crownsystem_test` database through `phpunit.mysql.xml` (SQLite cannot run the migrations because index names collide). |
| Audit trail | `spatie/laravel-activitylog` on entities (`LogsActivity`) |
| Realtime | Reverb + `NotificationService::send` |

## Accounting module layout

```
Modules/Accounting/
  Entities/         ~90 models (documents, lines, ledger, stock)
  Enums/            backed int enums with HasLabel/HasColor; one status enum per document
  Services/         one service per document/process; all writes in DB::transaction
  Services/Concerns PostsInventoryJournal (stock → journal bridge)
  Support/          permission registries (LedgerPermissions, InventoryPermissions, PurchasingPermissions), Number, WeightPieces …
  Livewire/Pages/   one folder per section (Add/Edit legacy pairs, Form for newer sections)
  tests/            Feature + Unit (MySQL)
```

## Ledger core

- **Chart of accounts** — `accounting_accounts`, tree with group (non-postable) and ledger (postable) accounts. Default chart from `DefaultChartOfAccountsSeeder`.
- **Account mapping** — `accounting_account_mappings` (`source_module`, `source_key` → `account_id`). Resolved by `LedgerAccounts`, `SalesLedgerAccounts`, `InventoryAccounts`; never hard-coded ids. A missing mapping is created on first use from a default account code; a non-postable mapped account throws.
- **Party sub-ledgers** — `PartyAccounts` creates one postable sub-account per customer under 1130 and per supplier under 2110 (both parents are groups). Customer/supplier balance = balance of that sub-account.
- **Warehouse inventory accounts** — one sub-account per warehouse under 1140, created on first use.
- **Journal** — `JournalEntryService` is the only writer of `accounting_journal_entries/lines`:
  - `createDraft` / `updateDraft` normalise lines (`round(…, 4)` money, 8-dp exchange rate), absorb sub-cent base-currency rounding into the largest line, and assert balance and postable/active accounts.
  - `post` locks the entry row, re-checks DRAFT inside the lock, rejects closed periods and closed fiscal years, then updates the `accounting_account_balances` summary (a cache; reports read journal lines, and `accounting:rebuild-balances` rebuilds it).
  - `void` never deletes: it posts a reversal entry (dated in the original period if still open, otherwise today) and marks the original VOIDED.
  - Entry numbers come from `Core\Services\ReferenceNumberService` (locked counter per month), so concurrent requests cannot collide.
- **Periods** — `FiscalYear` → monthly `AccountingPeriod`; closing a month or year blocks posting into it. `PeriodClosingVoucherService` moves profit to retained earnings.
- **Currencies** — every journal line keeps transaction currency, exchange rate and `debit_base/credit_base`. Rates come from `CurrencyExchangeRateService::rateFor(currency, date)`; a missing rate blocks the document. Settlement at a different rate posts an exchange gain/loss entry (`PaymentAllocationService::postExchangeDifference`).

## Inventory

- **Valuation** — perpetual inventory, **moving weighted average per product** (`accounting_product_costs`: quantity, value, average_cost). Receipts change the average; issues leave it unchanged.
- **Stock ledger** — `StockLedgerService` is the only writer of `accounting_stock_movements` and `accounting_stock_balances` (per product × warehouse × location). It locks the cost and balance rows, refuses issues beyond stock on hand (no negative stock), and refuses stock on a *main* item (weight items move only through their sub-items).
- **Reversal** — `StockLedgerService::reverse` replays a document's movements in reverse at their original cost; reversing a receipt is refused once the goods were issued.
- **Journal bridge** — `PostsInventoryJournal::postInventoryJournal` posts the stock-side entries in base currency (goods receipt: Dr warehouse inventory / Cr GRNI; delivery: Dr COGS / Cr inventory; transfer; stock count; opening balance).

## Document lifecycle (all postable documents)

`Draft → Posted → Cancelled`. Drafts are editable and deletable. Posting creates and posts the journal entry (and stock movements) inside one transaction with the document row locked. Cancellation never deletes: it voids the journal entry by reversal, reverses stock, releases allocations, and records `cancelled_at/by/reason`.

## Settlement

`PaymentAllocationService` links a source (receipt voucher or sales return → sales invoice; payment voucher or purchase return → purchase invoice) to an invoice, in the invoice's currency. It maintains `paid_amount/outstanding_amount` on invoices and `allocated_amount` on sources. Aging reports are computed from invoices and allocation dates, not from the cached columns.

## Authorization

- Every web route uses `->can('<Permission>')`; permissions are registered in `Support/*Permissions.php` and synced by migrations.
- Livewire actions call `$this->authorize('<Permission>')` before calling a service (the audit added this to four `save` actions that relied only on the route).
- There is **no company or branch data isolation**: `branch_id` is a reporting dimension on documents and journal lines, not an access boundary. Any user with a permission sees every branch's records. See `initial-findings.md` (A-11).

## Tests

- 45 module test classes in `Modules/*/tests` before the audit (47 after), using `RefreshDatabase` on `crownsystem_test`.
- Run: `vendor/bin/phpunit -c phpunit.mysql.xml` (whole suite) or `--filter <Class>`.
- Fixtures build their own currency, fiscal year, open period, default chart and mappings in `setUp()`; no test touches the development database.

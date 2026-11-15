# Audit execution plan and progress (started 2026-10-28)

Written for whoever continues this audit. Status is honest: ✅ done and verified by an executed test, 🟡 done but only partly verified, ⬜ not started.

## Priority order used

1. Ledger corruption, duplicate postings, data loss
2. Sub-ledger ↔ GL and stock ↔ GL reconciliation
3. Authorization
4. Broken end-to-end workflows
5. Calculations and reports
6. UI/UX that blocks work
7. Performance and cleanup

## Session 1 (2026-10-28)

| # | Item | Status |
|---|---|---|
| 1 | Map architecture, ledger core, posting paths (`system-architecture.md`, `accounting-workflow-map.md`) | ✅ |
| 2 | Baseline: full suite before changes — 271 tests, 270 passed; the 1 error was a test that had been silently skipped and started running mid-session because the code under it was being changed | ✅ |
| 3 | Idempotency / lock-and-recheck review of every post & cancel service | ✅ (sales invoice was the only outlier, A-06) |
| 4 | Sales settlement defects A-01…A-05, A-07 fixed with regression tests | ✅ `SalesSettlementCycleTest` (10 tests; all 10 fail on the original code) |
| 5 | Cross-module reconciliation with hand-calculated figures | ✅ `LedgerReconciliationTest` (5 tests) |
| 6 | Livewire exception-hook crash (A-13) | ✅ `SettlementPagesTest` (reproduced on original code) |
| 7 | Cancel actions on sales invoice and voucher screens, permission-checked | ✅ `SettlementPagesTest` |
| 8 | Action-level authorization on account/journal saves (A-08) | 🟡 code change only; covered indirectly by the route tests |
| 9 | Document keyboard shortcuts + focus ring + header consistency | ✅ tests + real Chrome session (light mode, RTL); dark mode not verified |
| 10 | Backfill migration for sales invoice outstanding amounts | 🟡 ran in every test migration and on the scratch `crownsystem_preview` DB; not run on the development database |
| 11 | Findings register, UI/UX findings, keyboard doc | ✅ |
| 12 | Full regression after all changes: `vendor/bin/phpunit -c phpunit.mysql.xml --log-junit …` → **323 tests, 1,608 assertions, 0 failures, 0 errors, 5 skipped** (Jetstream email-verification/registration, disabled by feature flags), 52 min | ✅ |

## Next sessions (not started)

| # | Item | Why |
|---|---|---|
| N-1 | Run `php artisan migrate` on staging, then compare `SUM(outstanding_amount)` of posted sales invoices with the customer sub-accounts | confirms A-01 backfill on real data |
| N-2 | Allocate historical posted sales returns (query in A-05) after review | historical aging still shows returned amounts |
| N-3 | Visual pass in dark mode (via the theme toggle) and in English/LTR on sales invoice, vouchers, goods receipt | only Arabic/RTL light mode was checked in the browser |
| N-4 | Move legacy sales order / quotation / purchase order / journal pages to the shared header (U-08) | consistency, shortcuts |
| N-5 | Foreign-currency end-to-end: sales invoice in USD, partial receipt at a different rate, return, cancel | exchange difference on the sales side is covered only by the shared allocation code |
| N-6 | Concurrency test with two real DB connections (two processes posting the same draft) | current tests prove the re-check, not the lock under true parallelism |
| N-7 | Reports review: income statement / balance sheet filters, GL pagination on large data, aging with unallocated credits shown | U-11, report correctness |
| N-8 | Decide on branch-level access (A-11) | requirement, not a bug |
| N-9 | Test-suite runtime (~46 min): `RefreshDatabase` re-migrates per process; consider schema dump (`php artisan schema:dump`) for the test DB | speeds every future regression run |
| N-10 | Production order, consolidated (China) purchase orders, period-closing voucher: end-to-end ledger tests | not covered in this session |

## How to verify locally

```bash
vendor/bin/phpunit -c phpunit.mysql.xml --filter 'SalesSettlementCycleTest|LedgerReconciliationTest|SettlementPagesTest'
vendor/bin/phpunit -c phpunit.mysql.xml          # full suite, ~45 minutes
```

Tests use the dedicated `crownsystem_test` database (forced in `phpunit.mysql.xml`); nothing touches the development database. Only one phpunit process may run at a time because they share that database.

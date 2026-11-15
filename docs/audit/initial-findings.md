# Findings register (audit of 2026-10-28)

Written for the developers and the finance lead deciding whether the system is ready for staging. Each finding was reproduced from the code path (and, where marked, by a failing test) before it was fixed.

Severity: **Critical** = corrupts the ledger; **High** = ledger and sub-ledger disagree, or a required business operation is impossible; **Medium** = wrong behaviour with a workaround; **Low** = defence in depth / consistency.

| ID | Module / workflow | Severity | Status |
|---|---|---|---|
| A-01 | Sales → posted sales invoice never set `outstanding_amount` | High | Fixed + backfill migration |
| A-02 | Sales → posted sales invoices could not be cancelled | High | Fixed |
| A-03 | Sales/Purchases → bounced or cancelled cheque left its voucher posted and allocated | High | Fixed |
| A-04 | Sales → receipt vouchers could not be cancelled | High | Fixed |
| A-05 | Sales → sales returns did not settle their invoice; cancelling a return did not release it | High | Fixed (new returns); historical returns documented |
| A-06 | Sales → `SalesInvoiceService::post` did not re-check status after the row lock | Low | Fixed |
| A-07 | Sales → an order whose only invoice was cancelled could never be invoiced again | Medium | Fixed (with A-02) |
| A-08 | Ledger UI → four `save` actions authorised only at mount | Low | Fixed |
| A-09 | Vouchers UI → no cancel action on voucher screens although the permissions exist | Medium | Fixed |
| A-10 | Sales invoice / voucher headers hard-coded status colours and posted without confirmation | Low | Fixed |
| A-11 | Security → no company/branch data isolation | Requirement gap | Documented, not changed |
| A-12 | Inventory → `ProductCost.value` is set to 0 when quantity reaches 0, dropping sub-cent residue that stays in the GL inventory account | Low | Documented |
| A-13 | UI (7 document pages) → any validation error or permission denial looped until PHP ran out of memory | High | Fixed |

---

## A-01 Posted sales invoices were never open for collection — High

- **Evidence.** `PurchaseInvoiceService::post` calls `PaymentAllocationService::refreshInvoice`; `SalesInvoiceService::post` did not. The column defaults to 0, and `PaymentAllocationService::openInvoices` filters `outstanding_amount > 0`.
- **Impact.** The allocation panel of every receipt voucher listed no invoices, "allocate automatically" did nothing, and invoice screens showed 0 outstanding. Customer receipts could not be matched to invoices from the UI at all. GL balances were correct; the sub-ledger settlement was unusable.
- **Root cause.** The sales side was not given the settlement step when allocations were introduced (2026-10-19 migration `…100002`).
- **Fix.** `SalesInvoiceService::post` refreshes paid/outstanding after posting. Migration `2026_10_28_000001_backfill_sales_invoice_settlement` recomputes `paid_amount`/`outstanding_amount` of existing posted sales invoices from their allocations (derived values only; no journal touched; idempotent).
- **Regression test.** `SalesSettlementCycleTest::test_a_posted_sales_invoice_is_open_for_receipt_allocation`.

## A-02 Posted sales invoices could not be cancelled — High

- **Evidence.** No `SalesInvoiceService::cancel`; `InvoiceStockFlowTest::test_cancelling_a_sales_invoice_returns_its_goods` was silently skipped (`markTestSkipped` when the method is missing). The permission `Sales Invoice Cancel` existed with no screen using it.
- **Impact.** A wrong posted sales invoice could only be corrected by an unrelated manual journal and stock adjustment, breaking traceability.
- **Fix.** `SalesInvoiceService::cancel` mirrors the purchase side: locks the invoice, refuses unless posted, refuses with posted returns or allocated receipts, voids the invoice entry by reversal, cancels the automatic delivery note / reverses direct stock issues and their COGS entry (`InvoiceStockService::cancelSalesInvoice`, which already existed), records who/when/why, refreshes settlement, and returns the sales order to *Preparing*.
- **Regression tests.** `SalesSettlementCycleTest` (cancel reverses revenue/COGS/stock and frees the order; blocked by allocations and returns; drafts cannot be cancelled) and the previously skipped `InvoiceStockFlowTest` test now runs.

## A-03 Cheque bounce/cancel left the voucher posted and allocated — High

- **Evidence.** `ChequeService::reverseOriginalEntry` voided the voucher's journal entry only. `PaymentVoucherService::cancel`'s docblock says ChequeService should call it with `$fromCheque = true`; no caller existed.
- **Impact.** After a bounced customer cheque the customer's ledger showed the debt again, but the invoice still showed *paid* (allocation intact), aging omitted it, and the voucher still looked posted. Sub-ledger ≠ GL.
- **Fix.** After reversing the entry, `ChequeService` cancels the receipt or payment voucher with `$fromCheque = true`, which releases allocations; the entry is not reversed twice.
- **Regression tests.** `SalesSettlementCycleTest::test_a_bounced_customer_cheque_cancels_its_voucher_and_reopens_the_invoice`, `…_a_cancelled_supplier_cheque_cancels_its_payment_voucher_and_reopens_the_invoice`.

## A-04 Receipt vouchers could not be cancelled — High

- **Evidence.** `PaymentVoucherService::cancel` existed; `ReceiptVoucherService` had none.
- **Fix.** `ReceiptVoucherService::cancel` (same rules as payment vouchers: posted only, open cheque must be handled from the cheques register, allocations released, entry reversed, reason recorded).
- **Regression tests.** `SalesSettlementCycleTest::test_cancelling_a_receipt_voucher_releases_its_allocations_and_reverses_its_entry`, `…pending_cheque_is_cancelled_from_the_cheque_register`.

## A-05 Sales returns did not settle their invoice — High

- **Evidence.** `PurchaseReturnService::post` allocates the return to its invoice and `cancel` calls `deallocateAll`; `SalesReturnService` did neither.
- **Impact.** After a sales return the customer GL was credited but the invoice outstanding and the receivables aging still showed the full amount.
- **Fix.** Posting a sales return allocates it to its invoice (up to the outstanding amount; any excess stays as customer credit); cancelling releases it.
- **Regression test.** `SalesSettlementCycleTest::test_a_sales_return_settles_its_invoice_and_aging_agrees_with_the_ledger`.
- **Remaining risk.** Sales returns posted *before* this fix are not allocated. They can be allocated from the allocation panel of each return. A bulk correction was deliberately not automated (it would create settlement records in production without review). Query to list them:

  ```sql
  SELECT r.id, r.reference_no, r.total_amount
  FROM accounting_sales_returns r
  LEFT JOIN accounting_payment_allocations a
    ON a.source_type = 'Modules\\Accounting\\Entities\\SalesReturn' AND a.source_id = r.id
  WHERE r.status = 2 AND r.deleted_at IS NULL AND a.id IS NULL;
  ```

## A-06 Sales invoice post did not re-check status inside the lock — Low

- **Evidence.** Purchase invoice, vouchers and returns re-check `canBePosted()` after `lockForUpdate`; the sales invoice did not. A second concurrent request was stopped only by an unrelated error ("Only draft journal entries can be updated").
- **Fix.** Re-check added; the second request now gets the correct message and nothing is written.
- **Regression test.** `SalesSettlementCycleTest::test_a_stale_draft_cannot_post_the_invoice_twice`.

## A-07 Cancelled invoice blocked re-invoicing the order — Medium

- `assertSalesOrderCanBeInvoiced` and the order picker on *Add sales invoice* counted cancelled invoices. Both now use the new `SalesOrder::activeSalesInvoices()` (same pattern as `PurchaseOrder::activePurchaseInvoices()`).

## A-08 Save actions authorised only at mount — Low

- `Account/Add`, `Account/Edit`, `JournalEntry/Add`, `JournalEntry/Edit` `save()` relied on the route's `->can()` at mount. A user whose permission was revoked mid-session could still save. Each `save()` now calls `$this->authorize(...)` with the route's permission.

## A-09 / A-10 Voucher and sales invoice screens

- Sales invoice, receipt voucher and payment voucher pages now use the shared `document-header` (status colour and label from the enum, `Save Draft`, **Post with confirmation**, **Cancel with optional reason and confirmation**, cancelled banner). Cancel is shown only to users with the `… Cancel` permission and, for vouchers, only when there is no open cheque.

## A-11 No company/branch data isolation — requirement gap

`branch_id` is stored on documents and journal lines but nothing restricts which branches a user can read or post to. The system is single-company. If branch-level access is required, it needs a design decision (user ↔ branches table, global scopes on documents and reports, report filters) — not a patch. Not changed.

## A-12 Product cost residue — Low

`StockLedgerService::issue` sets `ProductCost.value = 0` when the quantity reaches zero. Any sub-cent rounding residue (≤ 0.0001 per movement) is dropped from the cost table while the same residue stays in the warehouse GL account. Not material at 4-dp precision; documented for the inventory-valuation reconciliation report.

## A-13 Validation errors and permission denials crashed the document pages — High

- **Evidence.** Found while testing A-02's permission check: calling `cancel` (or the pre-existing `post`) on the sales invoice page without permission, or saving with an empty invoice date, ends in `Allowed memory size … exhausted` instead of a 403 or a field error. Receipt voucher pages (which do not use the grid trait) returned a clean 403.
- **Root cause.** `ManagesOrderItemsWithPiecesForm::exception($exception, $stopPropagation)`. Livewire calls this hook through the container with **named** parameters `e` and `stopPropagation` (`SupportLifecycleHooks::exception` → `Wrapped::__call` → `ImplicitlyBoundMethod::call`). `$exception` cannot be bound, so the call throws `BindingResolutionException` inside exception handling, which fires the `exception` hook again — forever.
- **Impact.** On sales order add/edit, purchase order add/edit, sales quotation, sales invoice and purchase invoice, every validation error and every authorization denial killed the request (500 / blank, worker out of memory). The hook was meant to toast validation errors; it never ran. Data was not corrupted (the exception happens before the service call).
- **Fix.** Parameter renamed to `$e` (Livewire's documented signature) with a comment explaining why the name matters.
- **Regression tests.** `SettlementPagesTest::test_a_validation_error_on_a_grid_page_returns_field_errors_instead_of_crashing` (reproduces the memory exhaustion on the original code) and the two `…requires_the_cancel_permission` tests (403 returned).

## Checked and found correct

- Journal posting: balance check in base currency, base-rounding absorption, row lock + re-check, closed month/year rejection, reversal instead of delete, unique entry numbers from a locked counter.
- Purchase cycle: GRNI clearing by actual receipt cost, price variance, service lines, direct shipment, cancellation guards, purchase return allocation, exchange difference on allocation.
- Stock ledger: row locks on cost and balance, no negative stock, main items cannot hold stock, reversal blocked after issue, transfers move value between warehouse accounts.
- Inventory documents, payment vouchers, purchase/sales returns: lock + re-check on post and cancel.
- Reports read posted and voided entries only (a voided entry and its reversal net to zero); drafts never reach reports.

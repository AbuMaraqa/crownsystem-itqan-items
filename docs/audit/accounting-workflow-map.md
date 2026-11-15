# Accounting workflow map (audit of 2026-10-28)

Written for the developers and the accountant who sign off the postings. Each row is the path traced from the user action through the service to the ledger. Account codes are the defaults of `DefaultChartOfAccountsSeeder`; the actual account is always resolved through the account mapping, so a mapped account replaces the code shown.

## Procure to pay

```
Purchase order (no prices) ─► Purchase quotation (prices, approve) ─► Goods receipt(s) ─► Purchase invoice ─► Payment voucher ─► Allocation
                                                                  └──────────── or the invoice receives what is still unreceived
Purchase invoice ─► Purchase return (settles the invoice first) ─► Allocation
```

| Step | Service | Journal entry (base currency) | Stock |
|---|---|---|---|
| Purchase order confirm | `PurchaseOrderService` | none | none |
| Purchase quotation approve | `PurchaseQuotationService` | none | none |
| Goods receipt post | `GoodsReceiptService::post` | Dr warehouse inventory (1140-x) / Cr GRNI 2125, at the receipt cost | receive at cost → moving average |
| Purchase invoice post (stock order) | `PurchaseInvoiceService::post` + `PurchaseInvoiceStockPosting` | Dr GRNI (actual receipt cost not yet cleared) ± purchase price variance 5115, Dr input tax 1180, Dr purchases 5110 for services, Cr supplier sub-account (2110-x) | auto-receives whatever earlier receipts left |
| Purchase invoice post (no order) | same | Dr warehouse inventory at net cost, Dr input tax, Cr supplier | receive at net cost |
| Purchase invoice post (direct shipment) | same | Dr COGS 5100, Dr input tax, Cr supplier | none (goods go straight to the customer) |
| Purchase invoice cancel | `PurchaseInvoiceService::cancel` | reversal of the invoice entry; auto receipt cancelled | reversed; blocked if the goods were already issued, if a posted return exists, or if payments are allocated |
| Payment voucher post | `PaymentVoucherService::post` | Dr supplier / Cr cash or bank | — |
| Allocation | `PaymentAllocationService::allocate` | only when rates differ: exchange loss 5520 / gain 4330 against the supplier | — |
| Payment voucher cancel | `PaymentVoucherService::cancel` | allocations released, entry reversed; a voucher with an open cheque is cancelled from the cheques register | — |
| Purchase return post | `PurchaseReturnService::post` | Dr supplier / Cr inventory at issued cost ± variance, Cr input tax | issue at the original receipt cost; allocated to its invoice |

## Order to cash

```
Sales quotation ─► Sales order (confirm) ─► Delivery note(s) ─► Sales invoice ─► Receipt voucher ─► Allocation
                                         └── or the invoice delivers what is still undelivered
Sales invoice ─► Sales return (settles the invoice first) ─► Allocation
```

| Step | Service | Journal entry | Stock |
|---|---|---|---|
| Delivery note post | `DeliveryNoteService::post` | Dr COGS 5100 / Cr warehouse inventory at average cost | issue |
| Sales invoice post | `SalesInvoiceService::post` | Dr customer sub-account (1130-x) / Cr revenue by type (4100 goods, 4110 services, 4150 transport), Cr output tax 2140; service cost Dr 5160/5320 / Cr applied cost, provider or accrual | `InvoiceStockService::issueSalesInvoice` delivers the remainder and posts COGS as a separate inventory entry |
| Sales invoice cancel *(added by the audit)* | `SalesInvoiceService::cancel` | invoice entry reversed, COGS entry reversed, auto delivery note cancelled | goods back to stock; blocked by posted returns or allocated receipts; the order becomes invoiceable again |
| Receipt voucher post | `ReceiptVoucherService::post` | Dr cash/bank / Cr customer | — |
| Receipt voucher cancel *(added by the audit)* | `ReceiptVoucherService::cancel` | allocations released, entry reversed | — |
| Cheque bounced / cancelled | `ChequeService` | voucher entry reversed **and the voucher cancelled with its allocations** *(fixed by the audit)* | — |
| Sales return post | `SalesReturnService::post` | Dr revenue by type, Dr output tax / Cr customer; Dr inventory / Cr COGS at the original issue cost | receive; now allocated to its invoice *(fixed by the audit)* |

## Inventory only

| Step | Service | Journal entry |
|---|---|---|
| Opening balance | `StockAdjustmentService` (type opening) | Dr inventory / Cr opening balance equity 3700 |
| Stock count / adjustment | `StockAdjustmentService` | shortage Dr 5140 / Cr inventory; surplus the reverse |
| Transfer | `StockTransferService` | Dr receiving warehouse / Cr sending warehouse at average cost; no entry for a move between locations of one warehouse |

## General ledger

| Step | Service | Notes |
|---|---|---|
| Manual journal | `JournalEntryService::createDraft/updateDraft/post` | only drafts are editable; posted entries are voided by reversal; document entries cannot be voided from the journal screen |
| Month / year close | `FiscalYearService::closePeriod/close` | blocks posting and reversal dates inside the closed range (reversal moves to today) |
| Year-end closing | `PeriodClosingVoucherService` | income and expense to retained earnings 3200 |
| Reports | `AccountingReportService` | trial balance, income statement, balance sheet, general ledger read posted + voided entries (a voided entry and its reversal net to zero); aging reads posted invoices and allocation dates |

## Not present in the codebase

Recorded as absent, not as defects: purchase requisitions, supplier quotation comparison across suppliers (the consolidated PO price offers cover the China import flow only), bank reconciliation, expense claims, fixed assets and depreciation, prepaid/accrual schedules, recurring entries, batch/serial/expiry tracking, units-of-measure conversion, debit notes and price-only credit notes, supplier refunds of overpayments.

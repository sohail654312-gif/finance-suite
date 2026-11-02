# Synthetic financial lifecycle evidence

This milestone implements `tests/Feature/FinancialLifecycleAcceptanceTest.php`. Run it with
`php artisan test --compact tests/Feature/FinancialLifecycleAcceptanceTest.php`.
Execution results and exact SHA belong in the milestone verification report. Until that run succeeds,
the scenarios below are **IMPLEMENTED / EXECUTION PENDING**, not accepted stages.

The authoritative lifecycle is the 32-step sequence in `FINANCE-SUITE-V1-ACCEPTANCE.md`.
This suite advances one reconciled ledger path; it does not certify all 32 stages.
No provider, bank, tax, payment, deployment, or production integration is invoked.

## Fixture and independent reconciliation

Two independent users own separate persisted teams. Tenant A creates a USD accrual entity, book,
2026 fiscal calendar and chart through authenticated APIs. January and February accounting periods
are created through the public `CreatePeriod` action. No setup-mode or approval policy is disabled.
There are no configured approval thresholds in this fixture; it does not prove reviewer approval.

Seven accounts start at zero. The following six January journals are created and posted through the
general-ledger HTTP API. `SALE-GL`, `BILL-GL`, receipts and payments are deliberately named ledger
fixtures: they are not sales-invoice, purchase-bill or payment-subledger acceptance.

| Reference | Debit | Credit | USD |
|---|---|---|---:|
| CAPITAL | Cash | Capital | 1,000.00 |
| SALE-GL | Receivable | Revenue | 240.25 |
| RECEIPT-GL | Cash | Receivable | 100.10 |
| BILL-GL | Expense | Payable | 60.15 |
| PAYMENT-GL | Payable | Cash | 40.00 |
| ACCRUAL | Expense | Accrued liability | 25.05 |

Expected January turnover is **1,465.55 debits = 1,465.55 credits**. Account balance assertions use
BC Math independently of report presentation: cash 1,060.10; receivable 140.15; payable credit 20.15;
accrued liability credit 25.05; capital credit 1,000.00; revenue credit 240.25; expense debit 85.20.
The trial-balance signed sum is exactly zero. January net income is 155.05, assets 1,200.25,
liabilities 45.20 and equity 1,155.05. Assets equal liabilities plus equity.

A balanced but unposted 99,999.99 draft must have no financial effect. Tenant B is denied journal
reads, posting and statements. Six report endpoint regressions independently reject Tenant B's book
from Tenant A, including comparative and drill-through. Cached identities with a revoked persisted
selection are rejected. Filament and Livewire reject a foreign book; Livewire rechecks ownership
after revocation between requests.

January is soft-closed through `TransitionPeriod`; posting the draft must fail with no state change.
The public `ReverseJournal` action creates a balanced 25.05 compensating entry in open February,
preserves the original posting timestamp and provenance, and rejects a second reversal. January
income remains 155.05. February cumulative assets remain 1,200.25; liabilities become 20.15 and
equity 1,180.10. The drill-through includes the original January expense rows. Posting actor,
posting timestamp, tenant ID, reversal link, state and line amounts are asserted as persisted evidence.
This is not proof of a complete tamper-resistant audit log.

## 32-stage disposition

“Pending” means executable assertions exist but their final CI result must be recorded. “Blocked”
means this scenario does not establish that stage; it is not a claim that every associated module is absent.

| # | Authoritative stage | Initial state / action / expected result | Disposition |
|---:|---|---|---|
| 1 | Entity | Owned user/team; API creates entity and book with persisted ownership | Pending |
| 2 | Fiscal year | No calendar; API creates 2026 calendar; action creates non-overlapping Jan/Feb periods | Pending |
| 3 | Currency | Entity configured USD; all fixture amounts USD; no FX or currency-change workflow | Partial, pending |
| 4 | Chart | Empty chart; API creates seven accounts with debit/credit normal balances | Pending |
| 5 | Opening balances | Zero ledger; CAPITAL posts 1,000.00 each side; opening-balance module not exercised | Partial, pending |
| 6 | Customer | No customer subledger object is created | Blocked |
| 7 | Invoice | SALE-GL verifies ledger effect only; actual invoice lifecycle unexecuted | Blocked |
| 8 | Receipt | RECEIPT-GL leaves receivable 140.15; allocation/payment records unexecuted | Blocked |
| 9 | Supplier | No supplier subledger object is created | Blocked |
| 10 | Bill | BILL-GL verifies ledger effect only; actual bill lifecycle unexecuted | Blocked |
| 11 | Payment | PAYMENT-GL leaves payable credit 20.15; provider/payment workflow unexecuted | Blocked |
| 12 | Bank import | No bank feed imported | Blocked |
| 13 | Reconciliation | Cash movement reconciles to ledger, but no bank statement matching/sign-off | Blocked |
| 14 | Asset acquisition | No asset register object or acquisition journal | Blocked |
| 15 | Depreciation | No depreciation run | Blocked |
| 16 | Accrual/prepayment | ACCRUAL posts 25.05 and reverses next period; prepayment workflow unexecuted | Partial, pending |
| 17 | Tax | No computation/filing/acknowledgement workflow | Blocked |
| 18 | Trial balance | Posted account turnover 1,465.55 per side and signed balances exactly zero; draft excluded | Pending |
| 19 | P&L | API net income 155.05 from posted 240.25 revenue minus 85.20 expense | Pending |
| 20 | Balance sheet | API assets 1,200.25 equal liabilities 45.20 plus equity 1,155.05 | Pending |
| 21 | Cash flow | API beginning zero, movement/ending 1,060.10; classification explicitly remains incomplete | Partial, pending |
| 22 | AR/AP reconciliation | Ledger receivable/payable reconcile, but customer/supplier open items unexecuted | Blocked |
| 23 | Close | Open January transitions to soft-closed; full operational closing checklist unexecuted | Partial, pending |
| 24 | Closed-period protection | January draft posting rejected and remains draft, balances unchanged | Pending |
| 25 | Authorized adjustment/reversal | Authenticated owner context; compensating February entry and duplicate rejection; independent reviewer threshold not exercised | Partial, pending |
| 26 | Year-end | No year-end closing/reopening workflow | Blocked |
| 27 | Engagement/workpaper | No engagement object created | Blocked |
| 28 | Evidence | Posting/reversal record evidence checked; engagement evidence workflow unexecuted | Blocked |
| 29 | Review/sign-off | Independent human review/sign-off workflow unexecuted | Blocked |
| 30 | AI analysis | No AI invoked | Blocked |
| 31 | AI non-posting proof | No adversarial AI action scenario | Blocked |
| 32 | Complete audit trail | Persisted timestamps/actors/provenance checked; full lifecycle durable audit evidence unexecuted | Blocked |

## Reporting isolation correction and limits

Previously, the six statement HTTP actions checked only the report capability and the raw query
checked book existence. Filament and Livewire similarly accepted a book ID after authentication.
`OwnedStatementBook` now refreshes the persisted identity and checks selected-team membership,
book ownership and the book's legal entity ownership at every public report read. Drill-through
also requires the account to belong to that book's entity/team. Filament's book ID is locked and
both UI surfaces repeat authorization before fetching report data.

The raw `StatementQuery` remains an internal query taking an explicit book ID. Its callers must
establish authority; this batch does not certify all jobs, exports or other indirect callers. The
report engine still presents rounded floating-point totals and cash movements remain unclassified;
large-value precision and complete cash-flow classification remain separate release work.

These tests run with synthetic isolated data. SQLite execution alone cannot prove PostgreSQL
concurrency or production acceptance. All original release gates, including 100% host executable
line coverage and the complete 32-stage lifecycle, remain mandatory.

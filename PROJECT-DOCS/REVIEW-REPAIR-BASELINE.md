# Finance Suite independent review and repair baseline

Review started 7 October 2026. Repository: `sohail654312-gif/finance-suite` only. This review assesses the existing diff without treating prior implementation decisions as evidence of correctness. PR #17 remains draft, open and unmerged. No main changes are authorized.

## Starting state

- Fetched main: `d41aa0211fc1ec54f57dd0d0d99b81412e386256`.
- Fetched PR #17 / `finance-suite/v1-completion`: `56c1f419c9a1938e1438d7f0f0be92dee0740dea`, five commits ahead, zero behind, 21 changed files.
- Local checkpoint: `9070dbcdf4c10cb359c7698594bc4f1dd6250440`, two unpublished repair commits, clean working tree. Their contents were preserved on `checkpoint/unpublished-date-repair-9070dbc`.
- Native pushes failed with GitHub HTTP 500. Publishing the same two patches through GitHub Git APIs succeeded with a non-forced, expected-head update to `ded7010e6ee30c4ce8b09365854d48ef7a55650a`. Local rebase skipped the identical patches; tree comparison passed.

## Starting cloud evidence

| Gate | Exact run / job | Observed result |
|---|---|---|
| Install at 56c1f41 | [37655282691](https://github.com/sohail654312-gif/finance-suite/actions/runs/37655282691) | PASS: locked install, package sources, clean SQLite migrations, npm install/audit/build |
| Docker at 56c1f41 | [37655282748](https://github.com/sohail654312-gif/finance-suite/actions/runs/37655282748) | PASS cloud build; registry publication skipped on PR |
| Tests at 56c1f41 | [37655282777](https://github.com/sohail654312-gif/finance-suite/actions/runs/37655282777) | FAIL Composer audit; later gates skipped |
| Integrity at 56c1f41 | [37655282707 / 112908707359](https://github.com/sohail654312-gif/finance-suite/actions/runs/37655282707/job/112908707359) | Regression FAIL; Pint PASS; PHPStan FAIL; full coverage still running at baseline |
| Security proposal at 56c1f41 | 37655282707 / 112908707063 | PASS resolver, strict validation and audit of proposed lock; committed lock remains vulnerable |
| Specified failing c1f4deb checkpoint | [37654972077 / 112907659578](https://github.com/sohail654312-gif/finance-suite/actions/runs/37654972077/job/112907659578) | Four balance-sheet cases fail `InvalidStatementRequest`; 15 pass, 40 assertions |

Current PHPStan output at 56c1f41 is an incomplete analysis with one severe error: missing `App\Jobs\SyncAccountingProviderConnectionJob`, encountered in `app/Http/Controllers/Api/QboWebhookController.php`. This is current output, not merely the historical main failure. Level 5 and the 100% host coverage threshold are retained.

The committed lock reports five advisories across Filament, Laravel, CommonMark and phpseclib. A cloud-generated proposal has SHA-256 `a75a5bba3c9b08161413ef30995986add0841d21b47bec757b47f062d1a07186`; strict validation and audit passed. Review found 15 updates and two added Composer helper dependencies. Its Filament 5.10.0 and Laravel 13.35.0 versions exceed the first patched versions, so a narrower resolution is required before acceptance. No advisory suppression is authorized.

## Entire existing PR diff review

| Material change / files | Disposition | Review finding |
|---|---|---|
| `CreateJournal.php` exact BCMath validation and lifecycle rejection | CORRECT BUT INCOMPLETE | Rejects negative/scientific/fractional-cent amounts and unequal totals; null lifecycle attributes and direct model/posting paths need separate safeguards. SQLite numeric storage and SQL sums do not prove large-value cent preservation. |
| `PostJournal.php` journal/account locks and ownership recheck | CORRECT BUT INCOMPLETE | Rechecks draft, balance, entity and active/manual accounts. Direct draft mutations and approval integration remain open. Production concurrency is not proven by SQLite. |
| `JournalLine.php` creation/update/deletion/reparent guards | CORRECT BUT INCOMPLETE | Model saves reject mutation of posted/reversed lines and cross-entity accounts. Model events do not cover bulk SQL or quiet writes. |
| `ReverseJournal.php` fixed book, type and provenance; original retained | CORRECT BUT INCOMPLETE | Correct history direction and transactional rollback; approval and supported-database concurrency still need proof. |
| `AccountBalances.php` includes reversed originals | CORRECT | Preserves original effects before reversal date and nets both entries afterwards. SQL aggregation precision remains a separate limitation. |
| `StatementQuery.php` natural category signs | CORRECT | Contra/refund balances retain their signs; liabilities/equity/revenue use credit presentation. |
| `StatementQuery.php` earliest-history retained earnings | INCORRECT | Raw SQLite date includes a time; strict public date validation rejects it. Normalize only internal dates. Retained earnings must also be tested against closing entries. |
| `StatementQuery.php` cumulative cash and explicit cash metadata | CORRECT BUT INCOMPLETE | Beginning plus movement reconciles to cumulative ending cash. Generic assets are no longer all cash. Classification is explicitly incomplete; consumers must preserve null/unknown section totals. |
| Statement drill-through ordering | CORRECT | Date, journal ID and line ID provide deterministic order. |
| `PostingToBook.php`, periods provider and listener | CORRECT BUT INCOMPLETE | Synchronous listener enforces configured closed/locked/cutoff/outside/overlap controls and reversals. Zero-calendar posting needs explicit setup/demo policy; disabled module and other ledger paths remain unproven. |
| `Team.php` hidden encrypted credentials/settings | CORRECT BUT INCOMPLETE | Reduces serialization exposure; does not establish all resource, export and log secret boundaries. |
| `FinanceIntegrityRegressionTest.php` | CORRECT | Keep existing assertions; date normalization requires an additional strict-public-input regression. |
| Finance Integrity workflow | CORRECT BUT INCOMPLETE | Read-only token and genuine gates are appropriate. Separate focused integrity from slow full verification, preserving every formatter, analysis and coverage gate. Resolver failures remain failures. |
| `MODULE-SOURCE-INVENTORY.json` | REQUIRES MORE EVIDENCE | Enumerates 466 module directories and source indicators; provisional capability classifications are not an acceptance audit. |
| Current implementation status, acceptance, release checklist, security review, project overview | STALE DOCUMENTATION | Old regression counts, unavailable Linux/Docker claims, absent period controls and blocked dependency solver must be replaced with exact dated evidence. No PARTIAL capability becomes COMPLETE without proof. |
| Phase 0 audit and roadmap | CORRECT BUT INCOMPLETE | Useful starting inventory and deferred priorities; historical observations must remain dated and distinguished from current results. |
| Financial statements README | CORRECT BUT INCOMPLETE | Honest unclassified cash disclosure; still requires consumer and large-value verification. |

## Root cause and first repair evidence

`balanceSheet()` queried `MIN(entry_date)` and passed the raw value into `profitAndLoss()`. Eloquent date storage on SQLite returned `YYYY-MM-DD 00:00:00`. Strict `validateDate()` correctly rejected that value. This is application logic, not an erroneous financial expectation. Commit `ded7010` formats the internal stored date as `Y-m-d`; public date/time input remains rejected.

[Focused integrity job 112914091492](https://github.com/sohail654312-gif/finance-suite/actions/runs/37656881023/job/112914091492) at ded7010: **27 passed, 64 assertions, 15.39 seconds**; module validation and foundation doctor PASS. The same workflow's dependency resolver job 112914091274 FAILS with GitHub authentication, so the overall workflow is not yet PASS. Full verification is still pending; ded7010 is not a frozen verified checkpoint.

## Open production boundaries

Modular legal entities lack team ownership; object policies do not establish full tenant isolation. Legacy journal reversal/mutation differs from the modular ledger. Provider mappings are incompletely company scoped and provider transaction import/sync classes are missing. Approval linkage, complete AR/AP/opening/year-end reconciliation, bulk write protection, production database locking, complete cash classification and large-number reporting precision remain unproven. Tax/provider acknowledgements and AI review transitions are not certified.

Preview must wait for all repair gates. The intended safe preview boundary is one isolated synthetic tenant, registration disabled, controlled login, no second tenant or production data, and live external financial actions blocked. No preview branch, deployment or verified accounting SHA exists at this baseline.

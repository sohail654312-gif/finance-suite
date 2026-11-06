# Finance coverage and security milestone — 6 November 2026

Status: implementation and cloud verification in progress. No release acceptance, merge, deployment, live customer access, or paid infrastructure is authorized.

## Source and evidence

- Main: `d41aa0211fc1ec54f57dd0d0d99b81412e386256`.
- PR #17: `4487a9a198b09049c4971d768ba0e733d04bc95d`; PR #22: `1a18051cb7e8ace8c7a68bc682af5300cb59a92f`; both open and unmerged at inspection.
- PR #25: `dd1f74eca89b27fa259934563f4272777740af96`, based on #22. Its three test fixture corrections establish balanced posted journals using the real posting service, retaining all denied mutation assertions. Focused journal and authorization suites passed; dependency audit blocked the overall job.
- Working branch: `finance-suite/coverage-security-85-90-20261106`, based on #25. Draft [PR #26](https://github.com/sohail654312-gif/finance-suite/pull/26) preserves this stack.
- Historical measured #22 baseline: 1,562 passed, nine failed, 6,036 assertions; 10,931 / 14,440 executable host lines = 75.6994%, 3,509 uncovered. [Run 41425419340](https://github.com/sohail654312-gif/finance-suite/actions/runs/41425419340). This is historical evidence, not the new baseline.
- Fresh strict baseline source: `1c0af3581cfc179c3d21aeecb9eeb0ae4588a527`, [run 42169655774](https://github.com/sohail654312-gif/finance-suite/actions/runs/42169655774). Functional and Clover results pending. Manifest, exact installation, Pint, PHPStan passed; locked audit reported 15 advisories in seven Symfony packages.
- Minimum Composer-generated Symfony patch proposal: [run 42169836416](https://github.com/sohail654312-gif/finance-suite/actions/runs/42169836416), pinned to 8.1.11 for the seven affected packages, minimal necessary companions only. Resolution/audit pending; no advisory ignores or manifest relaxation.

## Ranked coverage program

Ranked from the historical Clover statement inventory. Counts are opportunities, not claimed improvements; final comparisons must use the fresh baseline and final Clover artifacts with unchanged host source scope.

| Boundary | Historical uncovered lines | Behavioral evidence sought |
| --- | ---: | --- |
| Revolut controller/webhook | 356 | Owned active connection, exact decimal/currency/date imports, atomic retries, reconciled history protection, approved durable payment identity, webhook signatures |
| Sage/Xero/QuickBooks services | 430 | Eight outbound mappings per provider, inbound identity and ownership, absent invoice references, atomic malformed batches, retry idempotency |
| Plaid job/command/service/webhook | 246 | Current accounting authority, tenant identity, exact amounts, bounded cursor pagination, reconciled correction denial, signed event routing, secret-safe failures |
| External modules/base/manager/provider | 237 | Canonical paths, symlink containment, recursive dependencies and cycles, poisoned cache, actual route/config/publication registration, migration failures |
| HMRC authentication and PAYE/CT models | 162 | Persisted tenant token isolation, expiry/malformed grants, denied cross-company calculations and explicit absent statutory-data contracts |
| Host administrator user pages/forms/table | 223 | Actual permitted/denied Livewire behavior, password preservation/hashing, validated identity, role assignments, filters and secret redaction |

## Implemented corrections awaiting cloud proof

Provider payment references cannot match an arbitrary unsynced local invoice when the provider reference is absent. Quote/credit batches roll back local partial writes; provider fetch/token refresh occurs before the local transaction.

Plaid synchronizations verify persisted connection ownership and current accounting roles, serialize connection work, validate exact dates/decimal precision/status, reject cross-tenant identity reuse and reconciled financial corrections/removals, and roll back every page and cursor together. Provider errors are redacted. The import remains a bank-feed operation; no journal posting or reconciliation is manufactured.

Revolut imports retain authoritative tenant identity and exact currency amounts, reject ambiguous multi-leg mappings, protect reconciled history, and preserve approval and retry identity for outgoing requests. Unknown statuses remain pending. HTTP/webhook error logging does not include provider bodies or secrets.

HMRC token caches use persisted user, owned team, environment, and client identity. Unsupported payroll/tax aggregation fails explicitly when the required persisted legacy components are absent; existing calculations remain behind the checks. This does not certify PAYE, corporation-tax filing, tax-year periods, or legal calculations.

External module roots and class sources must remain inside canonical configured directories; dependencies, cycles, cache entries, and migration failures are checked explicitly.

## Verification rules and remaining acceptance

Focused coverage is diagnostic and is not release acceptance. Final full verification retains `--coverage --min=100 --coverage-clover=coverage.xml` and independent `scripts/verify-host-coverage.php`; existing required CI checks and source scope are unchanged. No excluded source, deleted failing test, generated empty invocation coverage, or lowered threshold is permitted.

Fresh/final exact counts, per-file deltas, exact source SHA, audit lock hash, full-suite assertions, PostgreSQL concurrency, and actual remaining gaps will be recorded after execution. Until then the 85% and 90% targets are unverified; the 100% release gate and full integrated acceptance are not accepted.

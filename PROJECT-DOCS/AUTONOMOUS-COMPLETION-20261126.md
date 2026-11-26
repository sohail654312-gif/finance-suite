# Finance Suite completion checkpoint — 26 November 2026

Status: **RELEASE BLOCKED**. This file supplements the existing capability inventory,
18 accounting gates and 32-stage lifecycle; it does not replace or waive them.

## Verified starting state

- Repository: `sohail654312-gif/finance-suite`; public; default branch `main`.
- Main: `d41aa0211fc1ec54f57dd0d0d99b81412e386256`.
- New isolated branch: `finance-suite/release-remediation-20261126`, based on PR #26
  at `8f79774475081a283cdd0bd6e37a772d6719b678`. Existing contributor branches remain unchanged.
- Open remediation chain: #17 → #22 → #25 → #26. #27 and #28 are merged into
  the candidate, not main. #29's three bank-disconnect commits are carried onto
  this branch with their original authorship; its original PR remains unchanged.
- Candidate [run 44114723217](https://github.com/sohail654312-gif/finance-suite/actions/runs/44114723217):
  **1,831 passed / 7,928 assertions; 13,135 / 14,759 host executable lines = 88.9965%**.
  Required coverage: **100%**, not the brief's historical 90%. Full release gate FAIL.
  Focused/static checks and PostgreSQL jobs passed at that historical SHA.
- Current local Composer audit: **41 advisories across five packages**:
  league/csv, spomky-labs/cbor-php, spomky-labs/pki-framework, web-auth/cose-lib,
  web-auth/webauthn-lib. November 17's clean audit is stale.
- Initial npm audit: **5 findings (2 critical, 3 high)**. A compatible lock refresh
  audits cleanly; build and CI must be rerun after that refresh.
- Strict local Composer installation cannot run: Windows lacks Horizon's required
  ext-pcntl. Docker, Podman, Lerd and a working WSL distribution are unavailable.
  Existing portable PHP 8.5 supports syntax checks. No extension bypass supplies
  strict application-install or runtime acceptance evidence.

## Implemented repairs and verification still required

1. The [Linux lock proposal](https://github.com/sohail654312-gif/finance-suite/actions/runs/45760952717)
   passed with unchanged root constraints and zero advisories. Its exact artifact
   `15090466134` was downloaded and hash-verified: ZIP SHA-256
   `c345cb7a76764dfd28b4170c36ac3f68a8b95cbfb1e83a11fcf667b2af46edcb`;
   lock SHA-256 `9b612f8822d945ac48f5987dd9e898820f96a598ef66c202b450f6b997748a97`.
   Applied unchanged at `bedeecd8`: league/csv 9.29.1, cbor-php 3.4.2,
   pki-framework 1.6.3, cose-lib 4.8.2, webauthn-lib 5.3.17; required addition
   symfony/polyfill-php81 1.38.1. No ignores or root-constraint changes.
   Local strict metadata validation and locked audit now pass. The initial local
   VCS lookup failed authentication; an authenticated retry was slow and cancelled.
   A bounded solver using unchanged locked metadata also succeeded, but its generated
   lock was **not** adopted: the normal Linux proposal above is authoritative.
2. Repair invoice/payment posting to consume locked persisted sources; reject missing
   sources, nonpositive amounts, invalid linked journals and foreign payment invoices;
   require revenue schedule team/total agreement. Calculate paid status with exact
   decimal arithmetic and tenant-owned posted payments.
3. Execute new behavioral and PostgreSQL contention tests, original posting/reversal
   suites, PR #29 denial/success cases, Pint, PHPStan and complete host coverage.
4. Reverify exact final source through `release-remediation.yml`. Standard Ubuntu
   runners only; read-only token; no deployment; small reports retained for one day.
5. Continue uncovered financial/tenant surfaces and native subledger/parallel-ledger,
   FX/tax, 32-stage lifecycle, recovery and operational acceptance. Consult existing
   `CURRENT-IMPLEMENTATION-STATUS.md`, `FINANCIAL-LIFECYCLE-EVIDENCE.md`,
   `FINANCE-SUITE-V1-ACCEPTANCE.md` and `SECURITY-REVIEW.md` for scope/evidence limits.

The first targeted run at `ca46678b` passed 81 cases and failed one genuine stale
invoice-status regression (362 assertions). Recalculation now locks/reloads the
persisted invoice before writing status. Final verification must prove that fix;
the expectation was not weakened. PHPStan and targeted Pint passed on that run.
The full npm audit and production frontend build passed locally and in Linux CI.

Docker's dependency stage now installs pcntl and uses strict Composer installation.
The verification workflow builds the image without registry publication. Production
hosting, image deployment, TLS, monitoring and recovery objectives remain unverified.

PostgreSQL verification creates only the dedicated `finance_suite_test` service DB,
runs migration/concurrency suites, dumps it with that server's own pg_dump and
restores into a separate `finance_suite_restore` DB. It compares exact row hashes
for users, teams, accounts, invoices, payments, journals, lines and migrations.
This is an isolated synthetic restoration check, not a production backup service,
storage backup, retention policy or recovery-time guarantee.

Local Actionlint 1.7.12 validates the changed workflows; its downloaded ZIP was
verified against the release checksum. A limited current-tree credential-pattern
scan found no matches; that is not a full history or exhaustive secret audit.

No live provider, real payment, production data, paid service, merge, release tag or
deployment is part of these checks. Contract fixtures must not be labeled live verification.

## Continuation

Working checkout: `work/finance-suite` in the 26 November task workspace.
Local command evidence is stored beside that checkout in `work/`.
After every source change, record exact commit and CI results here. Do not reuse
the starting SHA's passing tests as evidence for changed source. Final classification,
acceptance counts and unresolved dependencies must be recorded before handoff.

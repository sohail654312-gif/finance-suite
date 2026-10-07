# Security review — initial findings

7 October 2026. Targeted source review of lifecycle, ownership, policies, reports, providers, tax submission, workpapers, copilot and serialization. **Not exhaustive or certified.** V1 acceptance withheld.

| ID | Severity | Finding / evidence | Disposition |
|---|---|---|---|
| SEC-01 | Critical | Modular legal entities lack team ownership; core/GL policies check abilities only; controllers accept object/book IDs. | OPEN: owned graph, fail-closed queries and cross-user API/UI/report/export tests |
| SEC-02 | Critical | GL PostJournal ignores period/approval controls; UI calls directly; approval state separate by reference. | OPEN: canonical controls and direct/API/Filament/Livewire bypass tests |
| SEC-03 | High | Legacy journal mutation/deletion and reverse clearing posting/timestamp; lines lack history guards. | OPEN: reconcile authoritative ledger, preserve original history and opposite entries |
| SEC-04 | High | Modular posted-line insertion and reparenting evade guards. | CANDIDATE FIX; tests pending; bulk SQL/concurrency protection remains OPEN |
| SEC-05 | High | Float tolerance/caller lifecycle creation; absent posting ownership recheck/lock. | CANDIDATE FIX: exact decimals, lifecycle rejection, recheck/lock; direct model/concurrency proof remains OPEN |
| SEC-06 | High | Reversed originals excluded from reports; caller controls reversal book/provenance. | CANDIDATE FIX: original posted effects plus opposite entry, fixed book/ref/type and lock; tests pending |
| SEC-07 | High | QBO/Xero/Sage external-ID mappings not consistently tenant/company scoped; missing importer and sync job. | OPEN: scoped adapters, duplicate/retry/import reconciliation; live credentials unavailable |
| SEC-08 | High | SubmitTaxReturn marks Submitted from caller reference without provider acknowledgement. | OPEN: human approval, live/sandbox contracts and verifiable acknowledgement; fail closed in production |
| SEC-09 | High | Encrypted Team integration settings/keys exposed in serialization. | CANDIDATE FIX: hidden attributes/test; broader secrets/resources review OPEN |
| SEC-10 | High | CommonMark quadratic-time DoS [GHSA-3q6v-r5mr-hxv8](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8). | OPEN: dependency remediation BLOCKED by Composer GitHub authentication |
| SEC-11 | Medium | Filament MFA reauthentication [GHSA-7m6h-rg42-m449](https://github.com/advisories/GHSA-7m6h-rg42-m449); CommonMark HTML filter [GHSA-97jj-33gv-5xf9](https://github.com/advisories/GHSA-97jj-33gv-5xf9); phpseclib X25519 [GHSA-q97c-8qh3-fpc6](https://github.com/advisories/GHSA-q97c-8qh3-fpc6). | OPEN: compatible upgrades/tests; no suppression/risk acceptance |
| SEC-12 | Low | Laravel debug-page XSS [GHSA-jh5r-qr3c-85q8](https://github.com/advisories/GHSA-jh5r-qr3c-85q8). | OPEN: patch/production debug verification; no suppression |
| SEC-13 | Medium | Copilot key/status confirmation lacks full authenticated approver/provenance/mutation linkage; workpapers complete without final independent review. | OPEN: human-controlled transitions and evidence architecture |

Composer audit exit 1: **5 advisories / 4 packages**. Targeted no-install lock resolution failed at GitHub authentication, leaving lock unchanged. Platform-ignore flags were confined to that unsuccessful solver attempt; strict install/audit gates remain blocked/failed, never PASS.

[Starting-SHA Tests run](https://github.com/sohail654312-gif/finance-suite/actions/runs/34819330192), job 103897003504, failed in PHPStan on missing `App\Jobs\SyncAccountingProviderConnectionJob`. Tests/coverage skipped. Historical September audit success is stale against October advisories.

Remaining scope: authentication, IDOR, all model/query/UI/file/job/import/export/AI boundaries, OAuth, callbacks/webhook signatures/idempotency, rate limits, mutation authorization and durable trail. Model events do not guard bulk SQL; races require supported transactional database tests. No Critical/High production exception is accepted.

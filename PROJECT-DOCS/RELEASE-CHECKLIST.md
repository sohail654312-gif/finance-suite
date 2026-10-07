# Release checklist

**Production acceptance withheld. Do not merge.** PR #17 remains DRAFT. Preview must wait for a verified accounting checkpoint and a tested isolated demo boundary.

Current evidence at cf9e537, 7 October 2026; exact job links/counts are in [the review baseline](REVIEW-REPAIR-BASELINE.md).

| Gate | Result | Evidence / remaining work |
|---|---|---|
| Composer strict validation | PASS | Local and cloud |
| Composer locked audit | PASS | Zero advisories after minimum 14-package security patch; no ignores |
| Strict locked dependency install | PASS cloud | Install workflow; Windows pcntl remains unsupported |
| Module validation | PASS cloud | Finance Integrity job 112924393479 |
| Foundation doctor | PASS cloud | Same job |
| Pint | PASS | Standalone dirty formatter and cloud full test |
| PHPStan | PASS cloud | Job 112924393530, unchanged level 5 |
| Clean SQLite migrations | PASS cloud | Install run 37659887812 |
| Focused accounting regressions | PASS cloud | 33 cases / 76 assertions |
| Provider/schema recovery | PASS cloud | 55 cases / 156 assertions |
| Full tests / 100% host coverage | NOT ACCEPTED | 1,298 cases / 4,211 assertions PASS; actual coverage 65.8%. Forwarded-option ordering had bypassed the threshold; corrected without exclusions |
| npm ci / production audit / frontend build | PASS cloud | Install run; local audit zero vulnerabilities |
| git diff --check | PASS | Repeat after final edits |
| Docker image build | PASS cloud | Run 37659887820; registry publication skipped on PR |
| Verified synthetic demo / hosted URL | NOT STARTED | All repair gates must pass before preview branch |
| Production operational verification | BLOCKED | Tenancy, approvals, integrated lifecycle, providers, backup/restore and external infrastructure |

Gate counts measure verification evidence, not feature readiness. No production acceptance percentage is inferred from a green build. Local Docker is neither installed nor required.

- [ ] Executed evidence for all 18 integrity requirements.
- [ ] All 32 lifecycle steps reconcile statements, subledgers and history.
- [ ] Critical/High fixed/tested; Medium/Low dispositions recorded.
- [ ] Owned boundaries cover APIs/UI/reports/files/jobs/integrations/AI.
- [ ] One authoritative accounting result with verified migration.
- [ ] Authoritative rule versions and acknowledged live filing; safe sandbox contracts.
- [ ] Human audit opinions and AI mutation/signing safeguards.
- [ ] Complete synthetic demo isolated from production.
- [ ] Licence/attribution preserved.
- [ ] Backup/restore, retries/idempotency, health and rollback verified.
- [ ] Final SHA/branch/commits/files/exact test counts in closure report.

Heavy Linux PHP 8.5/Docker execution runs in cloud CI. Hosted preview additionally requires suitable hosting, persistent database, HTTPS and environment-managed demo credentials. Production operations/providers remain separate unresolved gates. Never place credentials in tracked files/logs; local Docker is not required. Inspect exact candidate CI before recording PASS.

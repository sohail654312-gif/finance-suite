# Release checklist

**BLOCKED. Do not merge or deploy.** Exact candidate-SHA evidence required.

| Gate | Local result | Evidence / follow-up |
|---|---|---|
| composer validate --strict | PASS | Valid metadata, exit 0 |
| composer audit --locked | FAIL | 5 advisories / 4 packages; High included; authenticated remediation required |
| php artisan module:validate | BLOCKED | No vendor/autoload; required pcntl unavailable on Windows; Linux workflow added |
| php artisan foundation:doctor | BLOCKED | Same dependency/runtime boundary |
| vendor/bin/pint --test | BLOCKED | No vendor/bin; standalone download unsuccessful; historical main result not candidate evidence |
| vendor/bin/phpstan analyse --no-progress --memory-limit=1G | BLOCKED locally; historical main FAIL | Missing sync job; preserve analysis level |
| Clean-database migrations | BLOCKED | No application dependencies; historical Install result not candidate evidence |
| Full automated tests | BLOCKED | 0 local application tests; preserve 100% host coverage threshold |
| npm ci | PASS | 79 packages, exit 0 |
| npm audit --omit=dev --audit-level=high | PASS | 0 vulnerabilities, exit 0 |
| npm run build | PASS | Exit 0, 38.48 seconds; generated tracked manifest restored |
| git diff --check | PASS | Repeat at final candidate |
| scripts/verify-release | BLOCKED | Application dependencies, Docker/kubectl unavailable; no passing claim |

Verified baseline release checks: **5/13 = 38.5%**. This measures passing check evidence, not functional maturity. Production release acceptance remains **0% (not accepted)** until all mandatory gates and lifecycle requirements pass.

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

Execution requires supported Linux PHP 8.5/extensions, authenticated Composer VCS access, database, Docker/kubectl and approved provider credentials. Never place credentials in tracked files/logs. Inspect draft candidate CI before recording PASS.

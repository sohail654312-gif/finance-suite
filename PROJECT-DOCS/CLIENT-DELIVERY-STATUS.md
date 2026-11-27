# Finance Suite client-delivery work — 27 November 2026

The latest mission is Finance Suite only. Other Codeedge repositories remain unchanged.
The [machine-readable ledger](CLIENT-DELIVERY-STATUS.json) separates implementation,
verification and acceptance. It supplements the canonical 18 accounting gates and
32-stage lifecycle; it does not replace them or change the 100% host coverage gate.

Verified baseline: `8003c20aca8fedae50ea9bfb14a09a6ba2338e1a`, 1,857 host tests /
8,055 assertions, zero failures/skips, eight dedicated PostgreSQL cases. Host coverage
is 13,182/14,784 = 89.1640%; release remains unaccepted. Prior raw reports and the
continuation package are preserved in the task's outputs directory.

Phase A is active: resolve twelve fresh Symfony advisories with a normal Linux
Composer-generated lock; repair reconciliation precision, locked HMRC detail
mutations, and persisted Copilot/automation membership and role boundaries.
Parallel writers own non-overlapping paths; only the primary writer commits/pushes.
Every source change requires focused and full exact-SHA evidence before VERIFIED.

The normal Linux dependency proposal passed in [run 45934915524](https://github.com/sohail654312-gif/finance-suite/actions/runs/45934915524).
Its artifact 15170731054 was hash-verified and applied unchanged: seven Symfony
packages, including the required http-foundation companion, now use 8.1.12.
Current local strict metadata validation and locked audit pass with zero advisories.
Application compatibility is still unverified on the changed source. The vendor
documents the [HTTP client fix](https://symfony.com/cve-2026-133750) and
[Mailer TLS fix](https://symfony.com/cve-2026-133751) at that version boundary.

Reconciliation now uses decimal strings and exact comparisons, rejects matched
foreign action accounts and rolls direct reconciliation writes back atomically.
Its 26 new cases require runtime verification. Copilot/automation API changes
recheck persisted selected-team membership and restrict record mutations to
persisted owners/admins/accountants while retaining read operations. Their actual
API regressions also await CI. These boundary repairs do not turn the existing
Copilot request recorder into a conversational or authoritative AI Accountant.

Phase B/C remain open: complete native accounting and authoritative subledger,
inventory/report reconciliation, every canonical acceptance scenario and meaningful
coverage of all remaining host lines. Statutory calculations cannot be certified
through fabricated schema or simplified financial expectations.

AI Accountant, secure financial tools, multilingual conversation, automation,
onboarding, receipt extraction and voice are tracked independently. Existing Copilot
recording is not conversational AI. No model receives unrestricted SQL, commands,
provider credentials or authority to post financial records. No paid inference or
external financial action is authorized by this engineering mission.

Production is not deployed. Missing hosting credentials and independent reviews
remain external requirements after software gates; unresolved code is engineering
work rather than an external blocker. No release acceptance or completion percentage
is claimed.

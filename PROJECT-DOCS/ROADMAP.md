# Finance Suite roadmap — reconciled to source

Initial inventory: d41aa0211fc1ec54f57dd0d0d99b81412e386256. Candidate review updated 7 October 2026; exact repair evidence is in REVIEW-REPAIR-BASELINE.md. No major V1 capability is COMPLETE. See CURRENT-IMPLEMENTATION-STATUS.md for 144 provisional capability classifications and actual source/test paths.

| Capability | Status | Evidence / next requirement |
|---|---|---|
| Foundation audit | PARTIAL | Live inventory/baseline; exhaustive boundary review remains |
| Company/users/roles/fiscal year/currency | PARTIAL | Core/team foundations; owned tenant/entity/book graph required |
| Chart/opening balances | PARTIAL | SaveAccount and opening actions; bypass safety and actual GL opening posting unproved |
| Journals/approvals/reversals/recurring | PARTIAL | 33 focused accounting cases pass; period/setup controls enforced, mandatory approval and other-ledger/bulk boundaries remain unproven |
| GL/AR/AP/bank/trial balance | PARTIAL | Ledger/subledger actions; authoritative result/control reconciliation required |
| Financial statements/comparatives | PARTIAL | Focused signs/cash/date/reversal fixtures pass; full activity classification, large-value presentation and integrated closing proof incomplete |
| Close/locks/segregation/year-end | PARTIAL | Configured modular posting periods enforced; integrated closing, legacy history, approvals and concurrency unproved |
| Assets/leases/revenue/inventory/payroll | PARTIAL | Existing domain actions; GL reconciliation unverified |
| IFRS policy/evidence/disclosure | PARTIAL | Policy rules/artifacts; specialized reviews/checklists missing |
| Global tax/effective dating | PARTIAL | TaxRule/CalculateTax; authoritative versions and reconciliation required |
| Verified UK/PK/SA/AE tax packs | BLOCKED | Authoritative rules and provider access unavailable; generic architecture only |
| Live government filing | BLOCKED | Caller reference is not provider proof; production must fail closed |
| Audit lifecycle | PARTIAL | Workpaper/evidence CRUD; engagement/risk/materiality/sampling/final human opinion missing |
| AI Accountant/Audit Assistant | PARTIAL | Request/confirmation records; reasoning, retrieval, provenance and human approval incomplete |
| Xero/QBO/Sage/import migration | PARTIAL | Real job/importer/schema repaired and inbound team mappings tested; outbound/multiple-company boundaries and full reconciliation remain unproven |
| Live integration certification | BLOCKED | Credentials and approved test accounts unavailable |
| UX/isolated synthetic demo | PARTIAL | Onboarding/navigation; integrated demo lifecycle/isolation unverified |
| Release verification | PARTIAL | Cloud audit/integrity/Install/Docker/Pint/PHPStan pass at cf9e537; full 100% coverage result pending, no verified preview or hosting access yet |
| Advanced forecasting/automation/specialist packs beyond V1 | FUTURE | Follow verified accounting/security gates and scoped review |

Order: accounting, tenancy, controls, reporting, tax, audit, AI, UX, release. No merge until all gates pass; never lower thresholds.

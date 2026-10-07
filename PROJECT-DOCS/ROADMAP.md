# Finance Suite roadmap — reconciled to source

Updated 7 October 2026 against d41aa0211fc1ec54f57dd0d0d99b81412e386256. No major V1 capability is COMPLETE. See CURRENT-IMPLEMENTATION-STATUS.md for 144 capability classifications and actual source/test paths.

| Capability | Status | Evidence / next requirement |
|---|---|---|
| Foundation audit | PARTIAL | Live inventory/baseline; exhaustive boundary review remains |
| Company/users/roles/fiscal year/currency | PARTIAL | Core/team foundations; owned tenant/entity/book graph required |
| Chart/opening balances | PARTIAL | SaveAccount and opening actions; bypass safety and actual GL opening posting unproved |
| Journals/approvals/reversals/recurring | PARTIAL | General-ledger candidate regressions; mandatory approvals/periods still disconnected |
| GL/AR/AP/bank/trial balance | PARTIAL | Ledger/subledger actions; authoritative result/control reconciliation required |
| Financial statements/comparatives | PARTIAL | StatementQuery; reversal candidate; cash flow/sign/fixtures incomplete |
| Close/locks/segregation/year-end | PARTIAL | State actions; posting enforcement and immutable history unproved |
| Assets/leases/revenue/inventory/payroll | PARTIAL | Existing domain actions; GL reconciliation unverified |
| IFRS policy/evidence/disclosure | PARTIAL | Policy rules/artifacts; specialized reviews/checklists missing |
| Global tax/effective dating | PARTIAL | TaxRule/CalculateTax; authoritative versions and reconciliation required |
| Verified UK/PK/SA/AE tax packs | BLOCKED | Authoritative rules and provider access unavailable; generic architecture only |
| Live government filing | BLOCKED | Caller reference is not provider proof; production must fail closed |
| Audit lifecycle | PARTIAL | Workpaper/evidence CRUD; engagement/risk/materiality/sampling/final human opinion missing |
| AI Accountant/Audit Assistant | PARTIAL | Request/confirmation records; reasoning, retrieval, provenance and human approval incomplete |
| Xero/QBO/Sage/import migration | PARTIAL | Services/migration modules; missing classes, tenant mappings and reconciliation |
| Live integration certification | BLOCKED | Credentials and approved test accounts unavailable |
| UX/isolated synthetic demo | PARTIAL | Onboarding/navigation; integrated demo lifecycle/isolation unverified |
| Release verification | BLOCKED | Windows pcntl, Composer authentication, failing audit and absent deployment tooling |
| Advanced forecasting/automation/specialist packs beyond V1 | FUTURE | Follow verified accounting/security gates and scoped review |

Order: accounting, tenancy, controls, reporting, tax, audit, AI, UX, release. No merge until all gates pass; never lower thresholds.

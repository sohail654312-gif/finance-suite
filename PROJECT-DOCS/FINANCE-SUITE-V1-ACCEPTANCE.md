# Finance Suite V1 acceptance

**NOT ACCEPTED.** Initial correction candidate; no professional tax/IFRS/audit certification.

| Accounting gate | Evidence / disposition |
|---|---|
| 1. Balanced journals | Candidate exact decimals; direct lifecycle/legacy bypass proof remains |
| 2. Cross-tenant journals/accounts impossible | Candidate cross-entity checks; owning-user boundary unresolved and Critical |
| 3. Posted history immutable | Candidate modular insertion/reparent guards; legacy/bulk SQL bypass unresolved |
| 4. Controlled correction/reversal | Modular opposite entries; candidate fixes book/provenance/reports; legacy reversal unsafe |
| 5. Closed-period protection | FAIL by source inspection: modular posting ignores periods |
| 6. Authorized reopening/evidence | Reasons/events exist; authenticated approval and durable evidence incomplete |
| 7. Approval bypass prevention | FAIL by inspection: GL posting and approval references disconnected |
| 8. Account hierarchy | SaveAccount guards cycles/parents; direct/concurrent bypass not proved |
| 9. GL/source reconciliation | Candidate reversal history fix; parallel ledgers unresolved |
| 10. Balanced trial balance | Query exists; full posted/subledger fixture not executed |
| 11. Posted-only statements | Candidate retains original posted effects plus opposite reversal; draft exclusion tests pending |
| 12. AR control reconciliation | NOT VERIFIED; open items/listener not proof of posted GL reconciliation |
| 13. AP control reconciliation | NOT VERIFIED; payment records not proof of posted GL reconciliation |
| 14. Explainable bank variance | Summary/sign-off exist; end-to-end fixture not executed |
| 15. Opening double entry | Validation/approval exist; actual GL opening posting not proved |
| 16. Deterministic FX/revaluation | Models/actions exist; decimal/GL reconciliation not executed |
| 17. Tenant-safe consolidation/intercompany | NOT VERIFIED; owned book/entity boundary missing |
| 18. Year-end audit history | Reference/state workflow; closing journal/reopening history not proved |

## Candidate regressions

`tests/Feature/FinanceIntegrityRegressionTest.php` defines **13 expanded cases**: four invalid/precision amount cases, caller posting-state rejection, posted-line insertion, reparenting, foreign account replacement, ownership recheck at posting, historical reversal/report/drill-through reconciliation, fixed reversal book/reference/type and hidden team secrets.

Local application tests executed: **0**. No assertion count/coverage measured. Syntax checks are not application tests. `finance-integrity.yml` adds focused Linux evidence without replacing full suite, analysis, audit or 100% host coverage gates. Exact candidate-SHA evidence is required before recording PASS.

## 32-step integrated lifecycle

All remain **BLOCKED / NOT EXECUTED** as one integrated acceptance scenario: entity; fiscal year; currency; chart; opening balances; customer; invoice; receipt; supplier; bill; payment; bank import; reconciliation; asset acquisition; depreciation; accrual/prepayment; tax; trial balance; P&L; balance sheet; cash flow; AR/AP reconciliation; close; closed-period protection; authorized adjustment/reversal; year-end; engagement/workpaper; evidence; review/sign-off; AI analysis; AI non-posting proof; complete audit trail.

The synthetic ledger regression is not a demo tenant or a substitute for this lifecycle. Execute public actions against reproducible fixtures and prove Assets = Liabilities + Equity, balanced trial balance, reconciled controls and complete evidence. Live providers may remain BLOCKED only behind complete safe contract/sandbox boundaries that never fabricate production acknowledgements.

Resolve Critical/High findings, establish supported Linux/authenticated dependency execution, run every release gate and complete the exhaustive review. Main remains unchanged until acceptance is green.

# Finance Suite Architecture

## High-Level Architecture

Users
  |
Authentication
  |
Company / Tenant
  |
Finance Core
  |
  +-- Accounting
  +-- Reporting
  +-- Documents
  +-- Permissions
  +-- Audit Trail
  |
  +-- IFRS Layer
  |
  +-- Tax Engine
  |      |
  |      +-- UK Pack
  |      +-- Pakistan Pack
  |      +-- Saudi Pack
  |      +-- UAE Pack
  |
  +-- Audit Engine
  |
  +-- AI Layer

## Core Database Areas
- tenants
- users
- roles
- permissions
- financial_periods
- accounts
- journals
- journal_lines
- customers
- suppliers
- invoices
- bills
- payments
- bank_accounts
- reconciliations
- fixed_assets
- documents
- audit_logs

## Future Compliance Tables
- accounting_policies
- compliance_checks
- tax_rules
- tax_returns
- audit_engagements
- audit_risks
- audit_procedures
- audit_samples
- audit_workpapers
- audit_evidence
- review_notes

## AI Rule
AI must never silently post financial transactions.

AI may:
- suggest
- analyse
- flag
- explain
- draft

Human approval should remain mandatory for sensitive accounting, tax and audit actions.

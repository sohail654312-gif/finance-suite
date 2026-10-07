# Finance Suite — Project Overview

## Vision
Build a professional Accounting, Tax and Audit platform using an existing open-source accounting foundation rather than starting from zero.

## Main Goals
- International-standard accounting foundation
- IFRS-oriented reporting and compliance controls
- Country-specific tax modules
- ISA-oriented audit workflow
- Strong audit trail and approvals
- Multi-company / multi-client support
- AI Accountant and AI Audit Assistant later

## Development Principle
Accounting accuracy comes first.
Tax and Audit must sit on top of a reliable accounting ledger.

## Project Separation
This project is completely separate from Codeedge.

DO NOT modify:
sohail654312-gif/codeedge

Finance Suite will have its own repository.

## Current State (7 October 2026)

Live source: Laravel 13, PHP 8.5, Filament 5, Livewire 4, 466 module directories, legacy and modular accounting, migrations, domain actions, APIs and tests. Finance Suite V1 is **NOT ACCEPTED for production**.

The audit found missing modular tenant ownership, disconnected journal approval/period controls, incomplete ledger/subledger integration, missing provider classes and vulnerable dependencies. Starting-SHA Tests CI failed in static analysis before running tests.

The completion branch includes initial journal/reversal/reporting corrections and 13 regression cases awaiting Linux execution. See [implementation status](CURRENT-IMPLEMENTATION-STATUS.md), [acceptance](FINANCE-SUITE-V1-ACCEPTANCE.md), [security review](SECURITY-REVIEW.md) and [release checklist](RELEASE-CHECKLIST.md). Existing MIT notices are preserved; no professional compliance or live filing certification is claimed.

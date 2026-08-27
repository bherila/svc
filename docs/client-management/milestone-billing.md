# Milestone Billing

Flat-fee billing for specific deliverables, independent of time entries. Part of the [Billing & Invoicing System](billing.md); milestone lines are one of the [invoice line item](billing.md#invoice-line-items) types and are emitted by the [cadence generation workflow](cadence-billing.md).

## Overview
Tasks can be designated as billable milestones by setting a non-zero `milestone_price` (currency, e.g., $500.00). This allows flat-fee billing for specific deliverables, independently of time entries.

## How It Works
1. An admin sets the `milestone_price` field on a task (minimum $0.00, rounded to nearest cent).
2. When the task is marked **completed**, it becomes eligible for billing.
3. During invoice generation, all unbilled completed tasks with `milestone_price > 0` whose `completed_at` date falls on or before the invoice's `period_end` are added as `milestone` line items.
4. If a task was completed in a period where the corresponding invoice is already **Issued** or **Paid**, the task is automatically carried forward to the next available draft or new invoice.
5. The task's `client_invoice_line_id` is set to reference the invoice line it was billed on.

## Invoice Generation Workflow with Tasks

When running "Generate Invoices" via the admin interface:

1. **Draft Invoice Detection**: The system finds or creates draft invoices for each billing period from the agreement start date through the current cadence window. Monthly agreements use monthly windows; non-monthly agreements use their configured cadence windows.
2. **Task Collection**: For each invoice period, the system identifies all completed, unbilled tasks (`milestone_price > 0`, `client_invoice_line_id IS NULL`, `completed_at <= period_end`).
3. **Draft Invoice Updates**: If a draft invoice already exists for the period, it is regenerated to include any newly completed tasks.
4. **Carry-Forward Logic**: If a task's completion date falls within a period that already has an **Issued** or **Paid** invoice, the task is automatically added to the next available **Draft** invoice instead.
5. **Immutability**: Non-draft invoices (Issued, Paid, Void) are **NEVER** modified. Their milestone line items remain locked and unchanged.

The **upcoming period preview** feature ensures that current-period work (time entries, expenses, recurring items, and milestone tasks) appears in a draft invoice before the period closes, giving admins visibility into what will be billed at period end.

## Visual Indicator
Tasks with a non-zero `milestone_price` display a **green badge** showing the price (e.g., `$500.00`). Completed tasks that have been invoiced show a `✓` in the badge.

## Invoice Deletion & Task Unlinking
When an invoice is soft-deleted (or a draft is regenerated):
- The `client_invoice_line_id` on associated tasks is automatically set to `null`.
- This allows the tasks to be billed again on the next invoice.

This behavior is implemented via Eloquent model events on `ClientInvoiceLine` (the `deleting` event unlinks tasks).

## Milestone Price Editing
Only admin users can set or modify the `milestone_price` field. Non-admin users cannot set this field via the API (it is silently ignored for non-admins).

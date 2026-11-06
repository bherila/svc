---
title: Invoices
audience: [operator]
routes: [workspaces.invoices, workspaces.finance, clients.invoices, clients.invoice, clients.proforma, svc.billing.invoices.index, svc.billing.invoices.show, svc.billing.schedules.show]
terms: []
---
An invoice bills approved work for one period. A client's Invoices tab lists them and has a **Draft an invoice** form for a manual one.

## Life of an invoice

1. A draft is built from approved time, recurring services and expenses.
2. Issuing it numbers it and makes it payable.
3. Payments reduce the balance until it is paid.

## The invoice document

The invoice page, its PDF and the client's copy are one document: lines grouped under Time, Recurring services, Expenses, Deferred work and Adjustments and credits, then the totals, the hours statement, what is still deferred and Appendix A, the time entries behind each line. The hours statement is frozen when the invoice is issued, so it never changes afterwards. In dark mode, Show as printed switches the page to the white paper the PDF is always drawn on.

The administrator copy includes your internal descriptions. The client copy never does, and shows the still-deferred list only where the agreement allows it.

## View proforma invoice

On a client's overview, View proforma invoice shows the next invoice for an agreement as the client would receive it, stamped as not an invoice. It is built from the work logged so far, is never stored, and changes as work is logged and approved.

## Recording a payment

Money received outside the system is recorded on the invoice, with the date it arrived. Recording a payment never charges anyone.

## Corrections

An issued invoice is never edited in place. A correction creates a new, linked invoice, so the history stays readable.

## Billing schedules

A schedule generates the invoice for each period of an agreement. Review the next run before you generate it.

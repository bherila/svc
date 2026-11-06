---
title: Invoices
audience: [operator]
routes: [clients.invoices, clients.invoice, svc.billing.invoices.index, svc.billing.invoices.show, svc.billing.schedules.show]
terms: []
---
An invoice bills approved work for one period.

## Life of an invoice

1. A draft is built from approved time, recurring services and expenses.
2. Issuing it numbers it and makes it payable.
3. Payments reduce the balance until it is paid.

## Recording a payment

Money received outside the system is recorded on the invoice, with the date it arrived. Recording a payment never charges anyone.

## Corrections

An issued invoice is never edited in place. A correction creates a new, linked invoice, so the history stays readable.

## Billing schedules

A schedule generates the invoice for each period of an agreement. Review the next run before you generate it.

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

The invoice page, its PDF and the client's copy are one document: lines grouped under Time, Recurring services, Expenses, Deferred work and Adjustments and credits, then the totals, the hours statement, what is still deferred and Appendix A, the time entries behind each line. Appendix B lists the expenses billed on the invoice with their receipts. The hours statement is frozen when the invoice is issued, so it never changes afterwards. In dark mode, Show as printed switches the page to the white paper the PDF is always drawn on.

The administrator copy includes your internal descriptions and every receipt. The client copy never does, and shows the still-deferred list only where the agreement allows it. In the client's portal and the PDF they download, Appendix B holds only the receipts you shared with them. The copy emailed to the client, and the one stored with the email, carries no Appendix B.

## View proforma invoice

On a client's overview, View proforma invoice shows the next invoice for an agreement as the client would receive it, stamped as not an invoice. It is built from the work logged so far, is never stored, and changes as work is logged and approved.

## Recording a payment

Money received outside the system is recorded on the invoice, with the date it arrived. Recording a payment never charges anyone.

## Corrections

An issued invoice is never edited in place. A correction creates a new, linked invoice, so the history stays readable.

## Billing schedules

A schedule generates the invoice for each period of an agreement. Review the next run before you generate it.

## Autopay

When a client has autopay on, an issued invoice says "Will be charged to Visa ending 4242 on" its due date, in the PDF, the email and the portal. The invoice page has an Autopay card:

- **Where it stands.** Will autopay on the due date, paid by autopay, processing, failed, "Not autopaid: over limit", "Not autopaid: no default method", skipped, "Not autopaid: left for manual collection", or needs review.
- **The attempt.** When it ran, the amount and the result. A failed charge shows the reason and is not retried; the invoice stays collectible and payment reminders resume. An attempt marked needs review was claimed but Stripe's answer was never read: check Stripe, then close it out (below) before collecting by hand.
- **Skip autopay for this invoice.** Owners and admins can skip one invoice; who and when are recorded. It is refused once autopay has tried to charge the invoice.
- **Close out this attempt.** For an attempt that needs review, ten minutes after it was claimed, owners and admins open the Stripe dashboard, look for a payment for this invoice, and record what they found. *The client was charged*: enter the PaymentIntent id (it starts with `pi_`). SVC reads it from Stripe and refuses it unless it belongs to this workspace and invoice and matches the attempted amount and currency; it then joins the payments list like any autopay charge and Stripe's events settle it. *Nothing was charged*: write what you checked. The invoice is left for manual collection and autopay never tries it again. Either way who and when are recorded in the attempt history. This never sends a charge.
- **The Stripe connection needs attention.** If Stripe refuses the workspace's key or account while autopay is charging, nobody is charged and nothing is shown to the client. The card says "Not charged yet: the Stripe connection needs attention" with a link to the workspace payments settings. The invoice keeps its attempt: autopay charges it on the first run after the connection is saved again, or after a working answer from Stripe. Until then autopay leaves the workspace alone for six hours at a time instead of asking Stripe every fifteen minutes.

The money arrives through the normal payment ledger and Stripe's events, like any online payment.


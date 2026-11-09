---
title: Your client portal
audience: [client]
routes: [portal.show, portal.billing, portal.approvals.approve, portal.approvals.dispute, portal.agreement, portal.invoices, portal.invoice, portal.proposal, portal.tasks, portal.time, portal.collaboration.index, portal.collaboration.show, portal.files.parent.show, portal.files.collaboration.show, portal.mcp.setup, portal.services, portal.services.show]
terms: []
---
The portal opens on two cards: what you owe, and how this period is going. Below them are your open tasks, recent approved time and your invoice history.

## What you owe

The Balance card adds up your open invoices, says if any are overdue, shows the one due first and the last payment received. Pay opens that invoice's payment page when it has one; View invoice shows the invoice itself.

## An invoice

An invoice opens as the document itself: what it covers, the totals, the hours statement for retainer work and, where your agreement allows it, the work still deferred. Appendix A lists the time behind each line. Beside it, the payment card shows what is owed and offers Pay by card or Pay in Square, whichever the business has set up. Download PDF gives you the same document on white paper. If your provider shared receipts for the expenses on the invoice, Appendix B lists those expenses with their receipts, and you can open each one. Emailed copies of the invoice do not carry the receipts; open the invoice in the portal for them.

## This period and the next invoice

The This period card shows hours used against the hours available, including any rolled over. Under it, Next invoice (estimate) lists what the next invoice is expected to carry: the retainer, recurring charges and any hours past the retainer so far. It is an estimate before tax and excludes expenses, and it looks no further ahead than the next invoice.

## Approving time and expenses

If you are your provider's billing contact, or you were given the permission, the home page has a To approve section. It lists the hours and expenses you have not yet decided on, grouped by provider and calendar month. Approve a whole month with one button, or open the month to approve or dispute single rows. A dispute can carry a note; it tells your provider, who corrects an issued invoice through a correction rather than by editing it.

Paying an invoice in full also approves the hours and expenses on it. That approval lasts only while the invoice stays paid: a refund takes it back. Something you approved or disputed yourself is never changed by a payment.

If a provider gave you reviewer access, or you are a billing contact of more than one client, the To approve card links to a single list of everything waiting, one card for each provider.

You cannot approve your own work. If you also work for your provider, your own hours and expenses stay in the list for someone else to approve.

Hours show as time only, with no rate. Receipts are not shown here; the ones your provider shared are on the invoice they belong to.

## Your retainer

Your agreement shows the hours included each period and what has been used.

## Proposals

A proposal waits for your signature. Accepting it creates the agreement.

## Billing and autopay

Billing lists the payment methods saved for your company and which one is the default. A contact with access to the whole company can manage them:

- **Add a payment method.** Choose **Add payment method**, tick that you want to save the method for future payments from your provider, then enter a card or US bank account in Stripe's secure form. Your provider never sees the number. A bank account can take a few days to verify; it appears here once Stripe confirms it. The first method you save becomes the default.
- **Make default.** Autopay charges the default method. Choose **Make default** on another method to change it; autopay stays on.
- **Remove.** Choose **Remove** and confirm. If the method is the default and autopay is on, autopay turns off, and you can turn it on again after choosing another default.

If your provider is not set up to take payments, or you only have access to some projects, the page says so and offers no buttons.

Autopay lets your provider charge that default method on each invoice's due date, so you do not have to pay by hand.

- **Turning it on.** A contact of your company turns autopay on here. You choose a limit, the most one invoice may be charged, and tick an authorization that spells out the method, the company and the limit. SVC stores that exact wording with your name and the time.
- **The limit.** An invoice over the limit is not charged. It says "Not autopaid: over limit" and stays yours to pay by hand.
- **Before the day.** Each invoice says the date it will be charged. You get nothing else beforehand.
- **One try.** SVC charges the remaining balance once, on the due date. If the charge fails it does not retry; the invoice says why, and the usual payment reminders resume.
- **Turning it off.** You can turn autopay off here at any time, or change the limit. Your provider can also turn it off. Removing your default payment method turns it off automatically.
- **If your provider turned it on for you.** An owner or admin can turn autopay on after you agreed outside SVC. They record how, and your billing contacts get an email with a link to this page to turn it off.

## Services

When your provider offers service packages, the **Services** tab lists the services you have with them, and what each one costs.

- **Adding a service.** If you can manage the whole company's account, the tab also lists the packages you can add. **Review and add** opens the service's agreement; nothing starts until you sign it.
- **Signing.** Read the terms and the agreement, type your name (and your title, if you like), tick that you accept, and choose **Accept**. Your name, the time and the exact version you signed are recorded and can't be changed. If your provider has changed the package since it was offered to you, the page shows what changed before you sign.
- **Starting.** Some services start as soon as you sign. Others wait until your provider reviews them; you get an email when the service starts, and its agreement then appears in the portal.
- **Contacts with access to some projects only** see the services for those projects, and can't add or sign one.

---
title: Service packages
audience: [operator]
routes: [packages.index, packages.create, packages.edit, packages.move, packages.requests.index]
terms: [Service package]
---

A **service package** is a fixed bundle of services at a fixed price, such as web hosting, or hosting with a monthly block of hours. You describe it once, publish it, and share its link. Clients read what's included and the price on a public page.

## Making a package

Open **Clients**, then **Packages**, and choose **New package**. A package has:

- **A public page**: its name, a one-line description, what's included, and the link name that ends its address.
- **What the client pays**: recurring charges such as hosting, a one-time setup fee, an optional retainer with included hours, and an optional rate for extra hours.
- **Questions for the request form**: what you need to know before you say yes, such as the website's address.
- **Agreement text**, which is copied onto each client's agreement.
- **Sign-up, invoicing and cancellation rules**: whether a setup fee or the first period is paid by card at sign-up, whether autopay is required, whether you review a sign-up before it starts, whether monthly invoices are issued automatically, and the notice period and minimum term.

## Publishing and versions

A package is a draft until you publish it. Publishing freezes that version, and later edits make a new draft that you publish when it's ready. Clients who signed up keep the terms they agreed to.

When you publish a new version, existing clients stay on theirs until you move them (below).

Publishing is refused, with the reason, when the package couldn't work. For example, a package that takes payment at sign-up needs a Stripe connection, and only a fixed-price package can issue its invoices automatically.

## Sharing

Each published package has its own link, so use **Copy link** to share it. Packages marked **Listed** also appear on your catalog page, whose link is on the Packages page. Unlisted packages are reachable only by their own link.

**Retire** closes a package to new sign-ups without changing anyone's agreement.

## Sign-up requests

Each published package has a request form. When someone sends it, the request waits under **Clients**, then **Sign-up requests**, and the dashboard's **Needs you** list counts the ones still open. Nothing is created from a request until you approve it.

Everything in a request was typed by the visitor, so it is shown as plain text and never as instructions. **Approve** creates the client and a project for the package, offers the package for acceptance and invites the contact to the portal; pick an existing client instead when the contact already has a company here. **Decline** emails the visitor, with your reason if you give one. A decided request can later have the visitor's details erased, which keeps only the package, dates and outcome.

## Accepting and starting

The client's contact signs in, reads the agreement and signs it, from the portal's **Services** tab. Clients who already work with you can add a package there themselves, without a request, if you mark it **Portal-addable**. Only contacts who can manage the whole company's account can add or sign a package.

If you publish a new version before the client signs, they sign the new version, and the signing page shows what changed. A retired package's open offers can still be signed on the terms offered.

A package set to **start automatically** starts as soon as the client signs. One set to **I review first** waits in the dashboard's **Needs you** list; open it and activate it from the client's agreement. The service starts on the day it's activated, the agreement is signed in the client's name, and the client gets an email. Packages that take a payment at sign-up can't be signed online yet.

## Cancellation

Every agreement, not only a package's, has cancellation terms: a **notice** period in days, an optional **minimum term** in months, and what the client can do in the portal: nothing, **request** a cancellation that you decide, or **cancel themselves**. A package's agreements start with the package's terms; change any agreement's under **Edit terms** on its page.

A cancellation always ends at the close of a billing period: the one in which the notice runs out, and never before the minimum term ends. Clients and you only ever choose from the dates offered, so a paid period is never cut short.

- **A request** appears in the dashboard's **Needs you** list. On the agreement's page, **Approve** it on the date asked for or another offered date (an earlier date marked *waive notice* skips the notice period), or **Decline** it. A note you write goes to the client in the email.
- **A self-serve cancellation** schedules the end straight away, and you get an email.
- **Ending an agreement yourself**: on its page, choose one of the offered dates under **End this agreement**.

An accepted cancellation doesn't end anything yet: the agreement stays active until the date, with that date as its end, and the client is emailed it. Until then you can **Undo** it on the agreement's page, which restores the end it had before; a client can undo only a cancellation they made themselves. On the date the agreement is terminated, and billing follows the usual rules for an ended agreement. Anything the client owes stays owed.

## Moving clients to a new version

Under **Versions and subscribers** on the package's page, each version shows how many active clients are on it. **Move to the published version…** opens the move: what changes, from what to what; when it takes effect; and the clients, each with what will happen to them.

- **When.** A move starts at the beginning of a billing period, after the client's notice period, so a client told today can still cancel before the new terms start. It also starts after any period you've already invoiced. The dates offered are the only ones you can choose; each says how many clients it suits.
- **Who.** Choose which clients to move. A client already in another move, or whose agreement ends first, can't be chosen, and the reason is shown. Agreements with an hourly rate or a retainer can't be moved yet, nor can agreements whose invoices Square collects: end them and offer the new version instead. Nor can a package with a charge on another schedule than the service itself, such as an annual licence in a monthly service or a one-time charge, on either version: the new agreement would charge it again. A move also keeps how often a client is billed: if the new version bills monthly and the old one quarterly (or any other change of billing period), the move is refused; offer the new version as a new service instead.
- **Autopay.** SVC never raises a client's autopay limit. When a client's limit is below the new price, it's flagged here, in the client's notice and in **Needs you**.

Scheduling emails each client the changes, the date and your message, and shows the same notice on the service in their portal.

On the date, the client's agreement ends the day before and a new agreement with the new terms starts on it, so every billing period is invoiced on the terms that applied to it and nothing already invoiced changes. Unused retainer hours don't carry over. A setup fee on the new version is for new sign-ups only: a client who moves is never charged it. A client who has cancelled by then keeps their terms until their agreement ends. If an invoice already covers the new period, the client asked to cancel before the date and you haven't answered, or since scheduling the agreement's billing period or recurring charges were changed or Square started collecting it, the move waits in **Needs you** until you settle it. If a move is still waiting when the first billing period on the new terms has ended, it doesn't start in the past: the client stays on their current terms, which keep invoicing every period, including that one, and **Needs you** asks you to cancel the move and schedule a new one. That period is invoiced even if you cancel the move, or schedule another, before it is. If its invoice can't be issued (**Needs you** says why), a later move waits until it is, so the client's agreement never ends with a period left unbilled.

You can **Cancel move…** while it's scheduled: before its date nothing changes for anyone, and after it only the clients still waiting stay on their terms. Each client is emailed.

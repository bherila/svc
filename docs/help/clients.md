---
title: Clients
audience: [operator]
routes: [clients.index, clients.show, clients.settings, clients.project]
terms: []
---
Everything for one client lives under that client. Pick a client from the switcher in the top bar.

## Finding a client

Use the switcher or the search shortcut. Choose **All clients** in the switcher to go back up to the whole workspace.

## Client settings

Settings is in the menu on the client's name. It holds the client record, its projects and who in the client's team can sign in to the portal.

For each person you can choose **Can approve time and expenses**. By default the client's billing contact can and nobody else can, and the setting shows which applies, for example "Billing contact (default)". Choose **Can approve** or **Cannot approve** to decide for that person either way; the choice overrides the billing contact default. Nobody can approve their own work, whatever you choose.

## Projects

A project groups time and tasks inside a client. Time is always logged to a project. See [time](help:time) for logging.

## Autopay

Client settings has an Autopay card. The client usually turns it on from their portal. For a client who agreed outside SVC, an owner or admin turns it on here: choose the limit per invoice, say how the client agreed (this is recorded with your name), and confirm. The client's billing contacts are emailed a notice with a link to turn it off.

Autopay charges the client's default saved Stripe payment method for each issued invoice on its due date, once, up to the limit. It never reaches back to an invoice that fell due before it was turned on. Either side can turn it off or change the limit; each change, with who made it and the wording or attestation, is added to the consent record, which is never edited. Removing the default method turns autopay off and records why. Upcoming charges lists the invoices it will charge next, and marks any over the limit as not charged. If Stripe has refused the workspace's connection, the card says "Not charged yet: the Stripe connection needs attention" and links to the workspace payments settings; the client is not told.


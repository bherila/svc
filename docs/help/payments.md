---
title: Payment connections
audience: [operator]
routes: [workspaces.payments]
terms: []
---
Each workspace is paid into its own Stripe or Square account. Clients pay invoices online through the account connected here.

## Stripe

Paste a Stripe restricted API key. SVC stores it encrypted and shows only its last four characters. Replace the key to rotate it, or disconnect to turn Stripe payments off for this workspace.

You can also paste the account's publishable key, which is what lets clients pay by card. It must be in the same mode (live or test) as the restricted key. When you save, SVC verifies that it belongs to the same Stripe account as the restricted key: it creates a short-lived SetupIntent with the restricted key, reads it back with the publishable key, and cancels it. If the keys are from different accounts, or SVC cannot complete the check, nothing is saved and the page says why.

### Is Stripe sending SVC everything?

SVC needs Stripe to tell it about payments, refunds, disputes, payouts and saved cards. Under the Stripe card, **Webhook** says either "all N events subscribed" (N being the count of events SVC handles) or which events are missing, and when SVC last looked. SVC looks when you save a key, when you press **Check again**, and once a day.

A warning appears when events are missing, the endpoint is disabled in Stripe, there is no endpoint at SVC's address (the address is shown to owners and admins), or the endpoint is in the other mode (test or live) than the key.

An owner or admin can press the fix button, then confirm:

- **Add missing events** subscribes SVC's endpoint to the events it lacks. Events it already has are kept, and no other endpoint in your Stripe account is touched.
- **Create endpoint** appears when Stripe has no endpoint at SVC's address. Stripe gives the new endpoint its own signing secret; SVC stores it encrypted, in place of the old one, and never shows it.

A disabled endpoint is not switched on for you. Enable it in the Stripe Dashboard, then press **Check again**. A connection that borrows another workspace's account has no webhook of its own; look at the workspace that holds the key.

### Restricted key permissions

In addition to the permissions for the payments you take, the restricted key needs:

- **Webhook Endpoints: Read**, so SVC can check the webhook. Without it the Webhook line says so.
- **Webhook Endpoints: Write**, so SVC can register this workspace's webhook when you save the key, and so the fix button works. Without Write you can still use SVC; the message names the permission to add.
- **SetupIntents: Write**, to verify the publishable key. Only needed when you enter one. Without it the save is refused with a message naming the permission; leave the publishable key blank to connect without it.

## Square

Paste a Square access token with its location ID, environment and webhook signature key.

## Reusing a connection

If you manage another workspace that already has a connection, choose it under "Or reuse a connection" instead of entering the key again.

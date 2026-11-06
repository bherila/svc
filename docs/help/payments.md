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

### Restricted key permissions

In addition to the permissions for the payments you take, the restricted key needs:

- **Webhook Endpoints: Write**, so SVC can register this workspace's webhook.
- **SetupIntents: Write**, to verify the publishable key. Only needed when you enter one. Without it the save is refused with a message naming the permission; leave the publishable key blank to connect without it.

## Square

Paste a Square access token with its location ID, environment and webhook signature key.

## Reusing a connection

If you manage another workspace that already has a connection, choose it under "Or reuse a connection" instead of entering the key again.

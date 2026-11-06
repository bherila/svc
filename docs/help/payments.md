---
title: Payment connections
audience: [operator]
routes: [workspaces.payments]
terms: []
---
Each workspace is paid into its own Stripe or Square account. Clients pay invoices online through the account connected here.

## Stripe

Paste a Stripe restricted API key. SVC stores it encrypted and shows only its last four characters. Replace the key to rotate it, or disconnect to turn Stripe payments off for this workspace.

## Square

Paste a Square access token with its location ID, environment and webhook signature key.

## Reusing a connection

If you manage another workspace that already has a connection, choose it under "Or reuse a connection" instead of entering the key again.

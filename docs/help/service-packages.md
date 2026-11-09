---
title: Service packages
audience: [operator]
routes: [packages.index, packages.create, packages.edit]
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

Publishing is refused, with the reason, when the package couldn't work. For example, a package that takes payment at sign-up needs a Stripe connection, and only a fixed-price package can issue its invoices automatically.

## Sharing

Each published package has its own link, so use **Copy link** to share it. Packages marked **Listed** also appear on your catalog page, whose link is on the Packages page. Unlisted packages are reachable only by their own link.

**Retire** closes a package to new sign-ups without changing anyone's agreement.

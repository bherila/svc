# UX overhaul mockups

These are the sources for the design canvas that goes with
[the UX overhaul spec](../ux-overhaul.md). Every client, person, amount and
address in them is made up.

Each `*.dc.html` file is one screen, written as plain HTML with inline styles.
`{{…}}` placeholders, `<sc-for>` and `<sc-if>` are filled in by the small
script at the bottom of each file. `canvas.json` lays the screens out on the
canvas.

| File | Screen |
| --- | --- |
| `Main.dc.html` | Admin dashboard: key figures, revenue chart, Needs you, coming changes, help panel |
| `Clients.dc.html` | Client list |
| `ClientOverview.dc.html` | Client overview: retainer, partner hours, next-invoice estimate, shared project |
| `Review.dc.html` | Reviewing a partner's hours |
| `Portal.dc.html` | Client portal: balance, this month, items to approve |
| `MyWork.dc.html` | A partner's "My work": tasks, time logging, months |
| `Help.dc.html` | In-app help patterns |
| `Proforma.dc.html` | Proforma (next-invoice) preview |
| `InvoiceAdmin.dc.html` | Operator invoice page; embeds `InvoicePdf.dc.html` |
| `InvoicePdf.dc.html` | Invoice PDF, page 1 |
| `PhoneDashboard.dc.html`, `PhonePortal.dc.html` | The dashboard and portal designed for a 390 px phone |

These files are reference material for building the screens. They are not
code to ship.

## Service packages

Screens for [service packages](../service-packages.md). They are fully static,
with no placeholders and no script, so each opens directly in a browser. Each
ends with a dashed "Design note" box. The box isn't part of the screen; it
explains a decision the spec relies on.

| File | Screen |
| --- | --- |
| `PackagesAdmin.dc.html` | Admin: package list under Clients › Packages, public catalog link and logo, Stripe readiness |
| `PackageEditor.dc.html` | Admin: package editor (public page, components, intake questions, agreement text, sign-up payment, activation, invoicing, cancellation terms, versions) |
| `PackageRequests.dc.html` | Admin: sign-up requests and review panel; dashboard Needs you excerpt |
| `AdminAgreementCancellation.dc.html` | Admin: an agreement's cancellation request, cancellation terms and upcoming version change |
| `PackageMigration.dc.html` | Admin: moving subscribers to a new package version |
| `PublicCatalog.dc.html` | Public: workspace catalog of listed packages |
| `PublicPackage.dc.html` | Public: package page with the request form, and its sent state |
| `PortalServices.dc.html` | Portal: Services tab (your services, earliest end date, add a service) |
| `PortalCheckout.dc.html` | Portal: review, sign and pay, with autopay consent, and the state after paying |
| `PortalCancel.dc.html` | Portal: request cancellation, the self-serve variant, and the pending state |

They were checked at 1440 px and 390 px with no horizontal page overflow.

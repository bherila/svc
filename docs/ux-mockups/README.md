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

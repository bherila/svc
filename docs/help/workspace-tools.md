---
title: History, MCP and Square
audience: [operator]
routes: [workspaces.operations, history.index, history.show, mcp.setup, square.explore]
terms: []
---
These live in the settings menu because they are about the workspace, not one client.

## Operations (classic tools)

The older all-in-one page keeps the tools that have not moved yet: logging time by project and the per-client file panels. Proposals, agreements, billing schedules and drafting an invoice moved to the client's Agreements and Invoices tabs, and the page links to them.

## History

The history library holds imported records from before this system.

## MCP setup

The MCP guide shows how to connect an assistant to your workspace with the access you choose.

Under Your connections, each connected app and MCP token has a Show all tools switch. An assistant normally starts with a short list of tools and asks for more; turn the switch on for one that cannot, and it lists every tool you may use. It grants nothing, it applies to that one connection only, and it takes effect the next time the assistant connects.

An assistant also needs the Read your SVC identity and available workspaces permission (`identity:read`) to find your workspaces. Leave it ticked when you choose access for an MCP token or connected app; without it the assistant can switch tools on but cannot learn which workspace to use, and it will ask you to grant it.

## Square

Square invoices mirrored from Square are shown for reference and can be linked to invoices here.

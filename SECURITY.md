# Security policy

Filament Kanban lets panel users move records between columns by drag and drop. The browser owns the board and the server re-checks every move, so the plugin's job is to make sure a drop never writes what the user may not change, never reaches a record outside the board's query, and never renders what a record holds as markup. Please report anything in that area privately so a fix can ship before details become public.

## Supported versions

Security fixes are released for the latest minor version of each supported release line.

| Version | Supported |
| ------- | --------- |
| 1.x     | Yes       |

## Reporting a vulnerability

Email **security@packstub.dev**. Do not open a public GitHub issue, discussion, or pull request for a security problem.

Include what you can: the package version (`composer show packstub/filament-kanban`), the Filament version, whether the board is a `KanbanResourcePage`, a `KanbanPage` or the trait on your own component, and a minimal reproduction.

What to expect: acknowledgment within 48 hours from a human, updates while we validate and fix, and coordinated disclosure with credit in the release notes unless you prefer otherwise.

## Scope

Highest priority:

- **Authorization of a move**: moving, reordering or bulk-moving a card the user may not update (the resource's `update` policy on a `KanbanResourcePage`, `authorizeMoves()` elsewhere), a locked card, a card in a hidden column, or into a column that does not accept it or is full.
- **Record reach**: any way for a browser to act on a record outside the board's query (another tenant's or team's record) through `kanbanMove`, `kanbanMoveMany`, a card action, a column action or a bulk action.
- **Rendering**: card titles, descriptions, badges, colours, URLs or column labels taken from records that reach the page as HTML, CSS or a `javascript:` link.
- **Realtime**: a board's `BoardChanged` broadcast reaching a channel the user may not listen to.

Also in scope: the pages, the trait, the renderless Livewire methods, the Alpine component and the published views and config.

Out of scope: vulnerabilities in Laravel, Filament, Livewire or SortableJS themselves (report upstream; we still ship a mitigation when the exposure is specific to this package), issues that need an already-compromised admin account or server, and missing hardening in your own application.

## Hardening guidance for integrators

- **Moves follow a policy.** On a `KanbanResourcePage` a move needs the resource's `canEdit($record)`. On a `KanbanPage` or your own component the board uses the model's policy `update` when one exists; without a policy anyone who can open the page may move every card. Set `authorizeMoves()` when that is not what you want.
- **Query is the boundary.** Every id the browser sends is resolved through the board's `query()`. Scope it to the tenant, the team or the user; a resource page's `getEloquentQuery()` already is.
- **Realtime channels are yours to authorize.** `broadcast()` sends on a private channel; define its authorization in `routes/channels.php` and use a tenant-specific channel name in a multi-tenant panel (see the configuration docs).
- **Colours and URLs from records are validated**, but a `Card::badge()` label or `Card::description()` is text: it is escaped, never rendered as HTML. Use an `Htmlable` only for markup you built yourself.

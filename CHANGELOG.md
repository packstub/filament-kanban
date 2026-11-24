# Changelog

All notable changes to `packstub/filament-kanban` are documented in this file.

## Unreleased

## 1.0.0 - 2026-11-24

The first stable release: the names you build against stay the same until 2.0. Everything from 1.0.0-beta.1 is in it.

- Swimlanes: rows by a second attribute, such as the assignee
- Bulk selection with "Move to…" and your own bulk actions; column actions in each column's menu
- Undo after a move, and realtime updates with Laravel Echo (`broadcast()`)
- Richer cards: description, progress bar, due date, badge icons, and `Card::locked()`
- Filters as Filament fields (select, multiple, searchable, toggle, date range), kept in the URL
- Table / Board switch, navigation badge, density toggle, keyboard and screen reader support, narrow screens
- Fixed "Move to…" in a card's menu: the board no longer throws in the console, focus returns to the moved card, and `kanban-card-moved` fires as it does after a drag
- `composer.json`'s homepage points at the package's page on packstub.dev

**Security:** moves are checked on the server against the model policy's `update` (or the resource's `canEdit()`), and cards the user may not update are locked; `Board::authorizeMoves()` changes the rule. Colours and card URLs from user data are sanitized.

**Upgrading from 0.x:** CSS classes and variables are renamed from `pk` to `ps-kanban`, and two protected `Board` methods are renamed. See [Upgrading](docs/upgrading.md). From the beta, run `composer update packstub/filament-kanban` and `php artisan filament:assets`.

Docs: https://packstub.dev/docs/filament-kanban

## 1.0.0-beta.1 - 2026-10-28

First beta of 1.0, and a big one: swimlanes, bulk actions, realtime updates and a board built from Filament's own components.

- Swimlanes: rows by a second attribute, such as the assignee
- Bulk selection with "Move to…" and your own bulk actions; column actions in each column's menu
- Undo after a move, and realtime updates with Laravel Echo (`broadcast()`)
- Richer cards: description, progress bar, due date, badge icons, and `Card::locked()`
- Filters as Filament fields (select, multiple, searchable, toggle, date range), kept in the URL
- Table / Board switch, navigation badge, density toggle, keyboard and screen reader support, narrow screens

**Security:** moves are now checked on the server against the model policy's `update` (or the resource's `canEdit()`), and cards the user may not update are locked; `Board::authorizeMoves()` changes the rule. Colours and card URLs from user data are sanitized.

**Upgrading from 0.x:** CSS classes and variables are renamed from `pk` to `ps-kanban`, and two protected `Board` methods are renamed. See [Upgrading](docs/upgrading.md).

Also fixed: moves saved in one transaction, reordering past the loaded cards, and the board no longer flashes unstyled on load.

Docs: https://packstub.dev/docs/filament-kanban

## 0.2.0 - 2026-09-30

- Card actions: any Filament action in a card's menu, or run on click with `cardAction()`
- Create a card right in a column with `createAction()`
- Columns from an enum, WIP limits (`Column::limit()`) and column summaries
- Polling with `poll('10s')`, and a `CardMoved` event after a move
- Avatars on cards, and "Load more" loads by itself on scroll
- Fixed console errors and lost styling on the card being dragged

## 0.1.0 - 2026-09-30

First public preview of Filament Kanban. The API may still change before 1.0.

- Boards on a page, a resource page or any Livewire component
- Optimistic drag and drop, with column rules checked on the server
- Instant search, select filters, paging per column and a "Move to…" menu
- Fold and hide columns, remembered per user, plus a focus mode
- Translations: en, ro, ru, de

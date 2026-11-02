# Changelog

All notable changes to `packstub/filament-kanban` are documented in this file.

## 1.0.0-beta.1 - 2026-10-28

First beta of 1.0, and a big one: swimlanes, bulk actions, realtime updates and a board built from Filament's own components.

- Swimlanes: rows by a second attribute, such as the assignee
- Bulk selection with "Move to…" and your own bulk actions; column actions in each column's menu
- Undo after a move, and realtime updates with Laravel Echo (`broadcast()`)
- Richer cards: description, progress bar, due date, badge icons, and `Card::locked()`
- Filters as Filament fields (select, multiple, searchable, toggle, date range), kept in the URL
- Table / Board switch, navigation badge, density toggle, keyboard and screen reader support, narrow screens

**Security:** moves are now checked on the server against the model policy's `update` (or the resource's `canEdit()`), and cards the user may not update are locked; `Board::authorizeMoves()` changes the rule. Colours and card URLs from user data are sanitized.

**Upgrading:** CSS classes and variables are renamed from `pk` to `ps-kanban` (`.pk-card` → `.ps-kanban-card`), so update a custom theme. A subclass overriding `Board::counts()` or `orderingFor()` needs `countsOf()` and `applyOrdering()`.

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

# Changelog

All notable changes to `packstub/filament-kanban` are documented in this file.

## Unreleased

- Column actions: `Column::actions([ColumnAction::make('archive')->action(fn (Builder $query) => ...)])` puts a `⋯` menu in the column's header. A `ColumnAction` (`Packstub\Kanban\Actions\ColumnAction`) gets the column's cards as `$query` (the board's query, the column, the user's search and filters), the `Column` as `$column` and the `Board` as `$board`; registered as `column:<column>:<name>` (an action object given to several columns is copied for all but the first); actions the app hides are left out of the menu, a hidden column's are hidden and refused, and the board reloads after one ran.
- Undo a move: the notification after a move ("Moved to Done") offers an **Undo** for five seconds (`undo(10)` for longer, `undo(false)` for none), when the way back is open. Undo is a move back through every rule and `moveUsing()`, with `CardMoved` fired for the reverse move; a refusal shows as usual.
- Realtime with Laravel Echo: `broadcast()` sends a `BoardChanged` event (queued once the transaction that made the change commits; `broadcastNow()` skips the queue) on a private channel (`kanban.<key slug>` by default, or yours) after every move, card action, create and column action, and the other tabs showing the board reload at once instead of polling; the tab that made the change ignores its own event, a burst of events is one request, and a change that arrives mid-drag is loaded once the drop settles. A broadcaster that is down never fails the change (reported). `poll()` still works without Echo, and both may be set.
- Fixed: a move refused by a `MoveRejected` still shows its message; any other exception thrown while saving (the database's, `moveUsing()`'s) is reported to the exception handler and answered with "The move did not go through." instead of its text, so a query with its bindings never reaches a notification. `Board::move()` lets such an exception propagate. Throw `MoveRejected` from `moveUsing()` for a message the user should see.
- Fixed: the board no longer flashes unstyled on page load (a search icon the width of the page) before its stylesheet, loaded on request, applies; it stays hidden until then.
- Fixed: a horizontal swipe over a column scrolls the board; the card list no longer captures the gesture (it scrolls vertically only, without overscroll containment), so a short column also lets the page scroll past it.
- Docs: a shorter Features list in the README and on the docs index, one line per area.

## 0.2.0 - 2026-09-30

- Card actions: any Filament action (`EditAction`, `DeleteAction`, your own) in each card's menu, on the card's record, resolved through the board's query. `cardAction('edit')` runs one when the card is clicked, in a modal or a slide-over; `Card::actions([...])` narrows the list per card. The board refreshes after an action. Components without Filament's action system (`HasActions`) get no card or create actions.
- Create in a column: `createAction(CreateAction::make()->schema([...]))` puts a "+" on every droppable column and sets the column attribute on the new record. `Column::creatable(false)` leaves a column out; the server refuses hidden, read-only and full columns.
- Enum columns: `columns(Status::class)` or `Column::fromEnum()` makes one column per case, with labels and colours from `HasLabel` and `HasColor`.
- WIP limits: `Column::limit(5)` shows `3/5` in the header and refuses moves and new cards once the column is full, on the server as well.
- Column summaries: `summarize(fn (Builder $query) => ...)` puts a line (a sum, an average) under each column title, kept current after every move.
- Polling: `poll('10s')` picks up other people's changes while nobody is dragging. A poll, like the refresh after an action, keeps the cards already loaded with "Load more".
- `CardMoved` event (`Packstub\Kanban\Events\CardMoved`) after a card changes column, and a `kanban-card-moved` browser event.
- `Card::avatar($url, $name)`: owners and assignees in the card's foot, initials when there is no picture.
- "Load more" loads by itself when it scrolls into view.
- The instant search also matches the names on a card's avatars.
- Fixed: Alpine no longer evaluates the dragged card's copy (Sortable's ghost), which logged "card is not defined" errors on every drag.
- Fixed: the dragged card keeps its background, fonts and dark-mode colours (the ghost lives outside the board).

## 0.1.0 - 2026-09-30

First public preview. The API may still change before 1.0.

- First release: `Board`, `Column`, `Card`, `Filter`; `KanbanPage` and `KanbanResourcePage`; the `InteractsWithKanban` trait for any Livewire component.
- Optimistic drag and drop on Filament's SortableJS, with server-enforced column rules (`visible`, `accepts`, `droppable`, `draggable`) and roll-back with the reason when a move is refused.
- Instant in-browser search refined by the server, select filters, paging per column, "Move to…" menu, folding and hiding columns remembered per user, focus mode without the sidebar.
- Translations: en, ro, ru, de.

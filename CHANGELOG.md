# Changelog

All notable changes to `packstub/filament-kanban` are documented in this file.

## Unreleased

- Swimlanes: `swimlanes('assignee_id', [...])` draws the board as rows by a second attribute, with `Lane::make()->label()->color()->collapsed()`, `Lane::fromEnum()`, or lanes derived from the data (first-seen order, an "Unassigned" lane for null). A drop in another lane sets the lane attribute too; `moveUsing()` receives the lane fourth, `CardMoved` carries `$lane`, counts and paging are per column and lane, WIP limits and summaries stay per column. Lanes fold and the fold is remembered with the other view preferences.
- Bulk selection: Ctrl/⌘-click, Shift-click or the checkbox on a card selects it; a selection bar replaces the toolbar with the count, "Move to…" (one `kanbanMoveMany` call, refused cards slide back with one notification), the board's `bulkActions([BulkAction::make(...)])` with the selection as `$records` loaded through the board's query, and Clear (Escape). Selection is on once the board has bulk actions, or with `selectable()`; on such a board Ctrl/⌘-click selects the card instead of opening its URL in a new tab (a middle click still does). Other boards are unchanged.
- `kanbanMove()` and `kanbanMore()` take a trailing `$lane`; `Board::move()` and `Board::getCards()` too. Nothing was removed.
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

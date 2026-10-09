# Changelog

All notable changes to `packstub/filament-kanban` are documented in this file.

## Unreleased

- Search and filters in the URL: the search and the active filters are mirrored into the page's query string (`?search=…&filters[owner_id][0]=3&filters[owner_id][1]=7&filters[mine]=1`) in place, and read back on load, so a filtered board can be bookmarked, shared and reloaded. An untouched board keeps a clean URL; values from the URL go through the same checks as any other. `persistInUrl(false)` turns it off (one URL-persisted board per page).
- Multi-select filters: `Filter::multiple()` shows a popover of checkboxes with the count on the button; the default narrows with `whereIn`, a `query()` closure gets the chosen values as a list. Values outside the options are dropped one by one.
- Toggle filters: `Filter::toggle()` is an on/off chip with the filter's label and no options; `query()` is required and gets `true` when the chip is on.
- Each filter's `type` (`select`, `multiple`, `toggle`) is in the board's config; new string `clear` in every language.
- A filter's `query()` closure gets the value as the options spell it (an `int` for `User::pluck('name', 'id')`), where a select used to pass the browser's string. Options given as a closure are resolved once per request, and only once a value is set.
- Fixed: a list sent to a select filter is ignored instead of raising a `TypeError`.
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

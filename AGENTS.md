# packstub/filament-kanban

Free (MIT) Filament v4/v5 plugin: a fast Kanban board. The browser owns the board (Alpine state drawn from JSON,
SortableJS from Filament); Livewire only answers renderless calls (`kanbanMove`, `kanbanRefresh`, `kanbanMore`).

## Commands

```bash
composer test               # Pest suite
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/Board.php` (definition + reads + the server-side move rules), `Column.php`, `Card.php`, `Filter.php` (the one
  Filter class shared with Gantt and Spreadsheet: select / multiple / searchable / boolean / toggle / dateRange) and
  `Enums/FiltersLayout.php`; `config/packstub-kanban.php` (`filters_layout`, `date_format`, `first_day_of_week`).
- `src/Concerns/InteractsWithKanban.php` (the Livewire side), `src/Concerns/KanbanActions.php` (card and create
  actions wired into Filament's action system: record through the board's query, hidden/after/mutate chained onto
  the app's own), `src/Pages/Kanban{,Resource}Page.php`, `src/Events/CardMoved.php`.
- `resources/views/board.blade.php` (a table-style container on Filament's own components, `ps-kanban-*` hooks),
  `filters.blade.php` + `partials/{select,date-picker}.blade.php` (the filter fields, shared with Gantt and Spreadsheet),
  `resources/dist/kanban.{js,css}` — hand-written, no build step; `kanban.css` starts with the shell shared by the three
  plugins (`--ps-kanban-*` variables). After editing them in a consuming app, run `php artisan filament:assets` there
  (assets are copied, not linked).
- `tests/Fixtures/TaskBoard.php` — a plain Livewire component (with Filament actions) holding a board, used by the
  Livewire and action tests.
- `docs/` — the packstub.dev pages (synced by `docs-sync.yml`); `docs/images/` is taken by
  `demos/packstub-demo/scripts/screenshots-kanban.mjs` (the suite app's `/sales` panel).

## Conventions

- Rules live on the server: anything the browser checks (`accepts`, `droppable`, `limit`…) `Board::move()` and
  `Board::canCreateIn()` check again.
- Column rules are evaluated once per request, never per card; cards are plain arrays.
- Errors: `kanbanMove` answers with a `MoveRejected`'s message only; any other exception is `report()`ed and answered with
  the generic `failed` string (a `QueryException` carries the SQL and bindings). `Board::move()` lets it propagate.
- Every change needs a test; `CHANGELOG.md` stays current. Strings in `resources/lang/{en,ro,ru,de}`.

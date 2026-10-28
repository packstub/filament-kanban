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
  `resources/dist/kanban.{js,css}` — hand-written, no build step; `kanban.css` starts with the shell shared by the three
  plugins (`--ps-kanban-*` variables). After editing them in a consuming app, run `php artisan filament:assets` there
  (assets are copied, not linked).
- The filters are Filament's own form components (`Select`, `Toggle`, `DatePicker`, a `Fieldset` for a date range), built
  by `Filter::toFormComponent()` and rendered through one schema on the component (`kanbanFiltersForm` → `$kanbanFilters`,
  filled on mount by `mountInteractsWithKanban()`, which also puts the URL's state in the fields' shape; so the component
  needs `HasSchemas` / `InteractsWithSchemas`, the pages have it). No copied Filament markup anywhere: a searchable
  select's search goes through Filament's own `callSchemaComponentMethod`. The state stays the browser's: each field
  dispatches its change with `afterStateUpdatedJs` (`ps-kanban-filter` `{name, value, edge?}`) to `setFilter()`, and the
  browser writes its state back into the fields without a request (`syncFields()`: `$wire.$set('kanbanFilters', …, false)`),
  so a restored URL and Reset show in the fields; the handlers ignore an echo equal to what they hold. The funnel dropdown
  is `:menu="false"` (a menu would take the keys typed in a search). The dropdown panel and the "above" row wear a
  table's `fi-ta-filters` / `fi-ta-filters-header` / `fi-ta-filters-heading` beside the `ps-kanban-filters-*` hooks.
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

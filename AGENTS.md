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

- `src/Board.php` (definition + reads + the server-side move rules), `Column.php`, `Card.php`, `Filter.php`.
- `src/Concerns/InteractsWithKanban.php` (the Livewire side), `src/Pages/Kanban{,Resource}Page.php`.
- `resources/views/board.blade.php`, `resources/dist/kanban.{js,css}` — hand-written, no build step. After editing
  them in a consuming app, run `php artisan filament:assets` there (assets are copied, not linked).
- `tests/Fixtures/TaskBoard.php` — a plain Livewire component with a board, used by the Livewire tests.

## Conventions

- Rules live on the server: anything the browser checks (`accepts`, `droppable`…) `Board::move()` checks again.
- Column rules are evaluated once per request, never per card; cards are plain arrays.
- Every change needs a test; `CHANGELOG.md` stays current. Strings in `resources/lang/{en,ro,ru,de}`.

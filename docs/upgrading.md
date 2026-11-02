# Upgrading

## From 0.x to 1.0

1.0.0-beta.1 renames a few things that a custom theme or a `Board` subclass may use. Nothing else needs changing: run `composer update packstub/filament-kanban` and `php artisan filament:assets`.

### CSS classes and variables

Every class and variable is renamed from `pk` to `ps-kanban`, the scheme Filament Gantt (`ps-gantt`) and Filament Spreadsheet (`ps-sheet`) use:

| Before | After |
| --- | --- |
| `.pk` | `.ps-kanban` |
| `.pk-card` | `.ps-kanban-card` |
| `--pk-col-width` | `--ps-kanban-col-width` |
| `body.pk-focus` | `body.ps-kanban-focus` |

The same goes for every other `pk-` class and `--pk-` variable. [Styling](styling.md) lists the current names.

### A `Board` subclass

The protected `Board::counts()` and `Board::orderingFor()` are gone. Override `countsOf(Builder $query)` and `applyOrdering(Builder $query, Column $column)` instead.

### Your own JavaScript

`kanbanMore()` now answers `{cards, icons}` instead of a bare list of cards. This only matters if your own script calls it.

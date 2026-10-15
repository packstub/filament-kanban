# Filament Kanban

![Filament Kanban](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/banner.jpg)

A fast, simple Kanban board for Filament v4 and v5. The browser owns the board, so a drop lands at once; the server checks every rule again and answers in the background. Free and open source (MIT).

- Repository: [github.com/packstub/filament-kanban](https://github.com/packstub/filament-kanban)
- Packagist: [packstub/filament-kanban](https://packagist.org/packages/packstub/filament-kanban)
- Support: [GitHub issues](https://github.com/packstub/filament-kanban/issues)

## Features

- **[Instant drag and drop](moves.md)**: cards land at once; a refused move slides back.
- **[Rules on the server](columns.md)**: enum columns, drop rules, WIP limits, locked cards.
- **[Filament actions on cards and columns](actions.md)**: edit, delete, create in a column, or your own.
- **[Column summaries](columns.md#summaries)**: a sum or an average under each column title.
- **[Rich, fast cards](cards.md)**: badges, due dates, progress, avatars; hundreds stay fast.
- **[Search, filters and paging](filters.md)**: Filament's filter fields, kept in the URL.
- **[Swimlanes](swimlanes.md)**: rows by assignee, priority or any attribute.
- **[Bulk selection](actions.md#bulk-selection)**: move or act on many cards at once.
- **[Shared boards](configuration.md#realtime-with-echo)**: realtime with Echo or polling, Undo on every move.
- **[Keyboard, screen readers and phones](configuration.md#keyboard)**: every move works without a mouse.
- **[Dark mode and translations](styling.md)**: English, Romanian, Russian and German.

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, the first board page, a board under a resource (its query, the Table / Board switch, the navigation badge), and embedding a board in any Livewire component |
| [Columns](columns.md) | Enum columns, who sees and moves what, drop rules, WIP limits, summaries, folding and order |
| [Cards](cards.md) | What a card shows: eyebrow, title, amount, badges, meta, avatars, accent and link |
| [Actions](actions.md) | Card actions, the click action, bulk actions on a selection, creating a card in a column, and column actions |
| [Swimlanes](swimlanes.md) | Rows by a second attribute: derived or defined lanes, what a drop sets, folding and paging |
| [Moves and events](moves.md) | What a move does, undo, `moveUsing()`, reordering, the `CardMoved` event and browser events |
| [Filters](filters.md) | Select, multiple, searchable, boolean, toggle and date-range filters, where they are shown, the search and filters in the URL |
| [Configuration](configuration.md) | Search, paging, polling, realtime with Echo, focus mode, density, and the full fluent API |
| [Styling](styling.md) | The table-style container, the `--ps-kanban-*` variables shared with Gantt and Spreadsheet, dark mode, translations |

## At a glance

```bash
composer require packstub/filament-kanban
php artisan filament:assets
```

```php
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Pages\KanbanResourcePage;

class DealBoard extends KanbanResourcePage
{
    protected static string $resource = DealResource::class;

    public function kanban(Board $board): Board
    {
        return $board
            ->query(fn () => Deal::query())
            ->columnAttribute('stage')
            ->columns(DealStage::class)
            ->card(fn (Deal $deal) => Card::make()->title($deal->company)->aside('$'.number_format($deal->amount)));
    }
}
```

## Requirements

PHP 8.3+, Laravel 12 or 13, Filament 4 or 5.

---

These pages are published at [packstub.dev/docs/filament-kanban](https://packstub.dev/docs/filament-kanban) from the package's `docs/` directory. Spotted a mistake? [Open a pull request](https://github.com/packstub/filament-kanban).

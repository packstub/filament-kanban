# Filament Kanban

![Filament Kanban](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/banner.jpg)

A fast, simple Kanban board for Filament v4 and v5. The browser owns the board, so a drop lands at once; the server checks every rule again and answers in the background. Free and open source (MIT).

- Repository: [github.com/packstub/filament-kanban](https://github.com/packstub/filament-kanban)
- Packagist: [packstub/filament-kanban](https://packagist.org/packages/packstub/filament-kanban)
- Support: [GitHub issues](https://github.com/packstub/filament-kanban/issues)

## Features

- **[Instant drag and drop](moves.md)**: a drop lands at once; if the server refuses, the card slides back with the reason.
- **[Rules on the server](columns.md)**: columns from an enum, per-user columns, drop rules, read-only columns and WIP limits.
- **[Filament actions on cards and columns](actions.md)**: edit in a slide-over, delete or run your own actions, create in a column, act on a whole column.
- **[Column summaries](columns.md#summaries)**: a sum or an average under each column title, kept current after every move.
- **[Light on big boards](cards.md)**: cards are drawn in the browser from JSON, so hundreds of them stay fast.
- **[Search, filters and paging](configuration.md#search)**: instant search, select filters and infinite scroll per column.
- **[Swimlanes](swimlanes.md)**: rows by assignee, priority or any attribute, derived from the data or defined, folded and remembered.
- **[Bulk selection](actions.md#bulk-selection)**: select cards with Ctrl/⌘- or Shift-click, move them together or run a bulk action.
- **[Shared boards](configuration.md#realtime-with-echo)**: realtime with Laravel Echo or polling, a `CardMoved` event, and an [Undo](moves.md#undo) on every move.
- **[Keyboard, screen readers and phones](configuration.md#keyboard)**: cards reachable and movable without a mouse, moves announced, one column at a time with tabs on narrow screens, a compact density.

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, the first board page, and embedding a board in any Livewire component |
| [Columns](columns.md) | Enum columns, who sees and moves what, drop rules, WIP limits, summaries, folding and order |
| [Cards](cards.md) | What a card shows: eyebrow, title, amount, badges, meta, avatars, accent and link |
| [Actions](actions.md) | Card actions, the click action, bulk actions on a selection, creating a card in a column, and column actions |
| [Swimlanes](swimlanes.md) | Rows by a second attribute: derived or defined lanes, what a drop sets, folding and paging |
| [Moves and events](moves.md) | What a move does, undo, `moveUsing()`, reordering, the `CardMoved` event and browser events |
| [Configuration](configuration.md) | Search, filters, paging, polling, realtime with Echo, focus mode, styling, and the full fluent API |

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

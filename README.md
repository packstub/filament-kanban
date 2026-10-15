# Filament Kanban

<div class="filament-hidden">

![Filament Kanban: a fast Kanban board for Filament](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/filament-kanban.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-kanban)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/filament-kanban/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/filament-kanban/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/filament-kanban.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-kanban)
[![License](https://img.shields.io/packagist/l/packstub/filament-kanban.svg?style=flat-square)](https://github.com/packstub/filament-kanban/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

</div>

A fast, simple Kanban board for Filament: a drop lands at once, the server checks every rule, and your Filament actions work on every card.

## Features

- **[Instant drag and drop](#instant-moves)**: cards land at once; a refused move slides back.
- **[Rules on the server](#columns-and-rules)**: enum columns, drop rules, WIP limits, locked cards.
- **[Filament actions on cards and columns](#card-actions)**: edit, delete, create in a column, or your own.
- **[Column summaries](#summaries-and-limits)**: a sum or an average under each column title.
- **[Rich, fast cards](#cards)**: badges, due dates, progress, avatars; hundreds stay fast.
- **[Search, filters and paging](#search-filters-and-paging)**: Filament's filter fields, kept in the URL.
- **[Swimlanes](https://packstub.dev/docs/filament-kanban/swimlanes)**: rows by assignee, priority or any attribute.
- **[Bulk selection](https://packstub.dev/docs/filament-kanban/actions#bulk-selection)**: move or act on many cards at once.
- **[Shared boards](https://packstub.dev/docs/filament-kanban/configuration#realtime-with-echo)**: realtime with Echo or polling, Undo on every move.
- **[Keyboard, screen readers and phones](https://packstub.dev/docs/filament-kanban/configuration#keyboard)**: every move works without a mouse.
- **[Dark mode and translations](https://packstub.dev/docs/filament-kanban/styling)**: English, Romanian, Russian and German.

## Compatibility

| Plugin | Filament | Laravel | PHP |
| --- | --- | --- | --- |
| 0.x | 4.x, 5.x | 12.x, 13.x | 8.3+ |

## Installation

```bash
composer require packstub/filament-kanban
php artisan filament:assets
```

No config, no migrations. The board uses the SortableJS that Filament already ships and plain CSS on your panel's palette, so there is nothing to add to your theme.

Add a board page next to a resource (or a standalone `KanbanPage`) and describe the board in one method:

```php
use App\Enums\DealStage;
use App\Models\Deal;
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Pages\KanbanResourcePage;

class DealBoard extends KanbanResourcePage
{
    protected static string $resource = DealResource::class;

    public function kanban(Board $board): Board
    {
        return $board
            ->query(fn () => Deal::query()->with('owner'))
            ->columnAttribute('stage')
            ->columns(DealStage::class)
            ->card(fn (Deal $deal) => Card::make()
                ->eyebrow($deal->reference)
                ->title($deal->company)
                ->aside(Number::currency($deal->amount, 'USD'))
                ->meta([$deal->title, $deal->close_at?->format('M j')])
                ->avatar($deal->owner?->avatar_url, $deal->owner?->name));
    }
}
```

Register it in the resource's `getPages()`: `'kanban' => DealBoard::route('/board')`. Under a resource the `query()` is optional (the board shows the resource's records, tenant and scopes included), `KanbanAction::make()` in the list page's header gives a Table / Board switch, and the `HasKanbanNavigationBadge` trait on the resource puts the number of cards in the navigation.

Read more: [Installation](https://packstub.dev/docs/filament-kanban/installation), including a board inside any Livewire component.

## Instant moves

![A pipeline board in Filament: six columns with totals, cards with amounts, badges and owners](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/docs/board.png)

A drop moves the card in the browser at once and asks the server with a small renderless call. The server loads the record through the board's `query()` and checks the column rules; your move logic runs; the card flashes when it is saved, or slides back with the reason when it is refused. Give your own logic to `moveUsing()` and throw to refuse:

```php
->moveUsing(function (Deal $deal, string $to, string $from) {
    if ($to === 'won' && ! $deal->contract_signed) {
        throw new \RuntimeException('Attach the signed contract first.');
    }

    $deal->update(['stage' => $to]);
})
```

Scope the query (tenant, team, date window) and every read, move and action goes through it.

Read more: [Moves and events](https://packstub.dev/docs/filament-kanban/moves).

## Columns and rules

![Dragging a card: the columns it may go to are highlighted, the others dimmed](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/docs/drag.png)

Build the columns from an enum, or list them. Every rule takes a closure and is evaluated once per request, never per card:

```php
->columns([
    Column::make('todo')->label('To do')->color('sky'),
    Column::make('doing')->color('amber')->accepts(['todo'])->limit(5),
    Column::make('review')->color('violet')
        ->accepts(['doing'])
        ->visible(fn () => auth()->user()->isReviewer()),
    Column::make('done')->color('emerald')
        ->accepts(['review'])
        ->droppable(fn () => auth()->user()->can('ship'))
        ->collapsed(),
])
```

| Method | What it does |
| --- | --- |
| `visible()` / `hidden()` | Leave the column out for this user: its cards are not loaded and nothing moves into or out of it. |
| `accepts([...])` | The columns a card may come from. `null` (default) means anywhere. |
| `droppable()`, `draggable()`, `readOnly()` | Who may drop in and drag out. |
| `limit()` | A WIP limit: `3/5` in the header, and a full column refuses moves and new cards. |
| `creatable()` | Whether the column offers "+" for the create action. |
| `collapsed()`, `sortBy()` | Start folded; this column's order. |

Read more: [Columns](https://packstub.dev/docs/filament-kanban/columns).

## Card actions

![A card's menu with Edit, Mark as hot and Delete above "Move to"](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/docs/card-actions.png)

Your Filament actions work on cards, with their forms, modals, slide-overs and confirmations. The card's record is the action's record, loaded through the board's query, and the board refreshes once an action has run. `cardAction()` picks the one a click on the card runs:

```php
->cardActions([
    EditAction::make()->schema(DealResource::fields())->slideOver(),
    Action::make('hot')->icon(Heroicon::OutlinedFire)->action(fn (Deal $record) => $record->update(['is_hot' => true])),
    DeleteAction::make(),
])
->cardAction('edit')
```

![Editing a deal in a slide-over, opened by clicking its card](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/docs/edit.png)

Read more: [Actions](https://packstub.dev/docs/filament-kanban/actions).

## Create in a column

![The "New deal" slide-over, opened from the Qualified column](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/docs/create.png)

`createAction()` puts a "+" in each droppable column's header. The form opens, and the new record gets that column:

```php
->createAction(CreateAction::make()->label('New deal')->schema([...])->slideOver())
```

Hidden, read-only, full and `creatable(false)` columns get no "+", and the server refuses a create into them.

Read more: [Actions](https://packstub.dev/docs/filament-kanban/actions#create-a-card-in-a-column).

## Summaries and limits

A line under each column title, from the column's query with the current search and filters, refreshed after every move:

```php
->summarize(fn (Builder $query) => Number::currency($query->sum('amount'), 'USD'))
```

Read more: [Columns](https://packstub.dev/docs/filament-kanban/columns#summaries).

## Cards

`Card::make()` with `eyebrow()` (small monospaced line), `title()`, `aside()` (right-aligned: an amount, a date), `description()` (two muted lines), `badge($label, $color, $condition, icon: ...)`, `meta([...])` (empty parts dropped), `due($date)` (red once past, amber on the day), `progress($done, $total)` (a thin bar), `avatar($url, $name)` (initials without a picture), `accent($color)` (a coloured left edge), `url()`, `searchText()`, `actions([...])` and `locked()` (one card that stays put, refused by the server too).

Read more: [Cards](https://packstub.dev/docs/filament-kanban/cards).

## Search, filters and paging

![Searching the board: the matching cards of every column, with the totals following](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/docs/search.png)

```php
->searchable(['reference', 'company', 'owner.name'])
->filters([
    Filter::select('owner_id')->label('Owners')->multiple()->options(fn () => User::pluck('name', 'id')->all()),
    Filter::toggle('mine')->label('Assigned to me')->query(fn (Builder $query) => $query->where('owner_id', auth()->id())),
    Filter::dateRange('due_at'),
])
->filtersLayout(FiltersLayout::Dropdown) // behind a funnel button (the default), Toolbar, or AboveContent
->perColumn(50)
```

Typing filters the loaded cards at once, then the server brings the matching cards of every column. A `Filter` is one of Filament's own fields: a select (one option, several with `multiple()`, typed with `searchable()`), a Yes / No select (`boolean()`), a checkbox (`toggle()`) or a date range (`dateRange()`); every value is checked again on the server. The search and the active filters stay in the URL, so a filtered board can be bookmarked and shared. Each column loads its cards in pages as you scroll.

Read more: [Filters](https://packstub.dev/docs/filament-kanban/filters).

## Configuration

```php
$board
    ->reorderable('sort')   // users order cards inside a column
    ->sortBy('due_at')      // default order (a column can override it)
    ->poll('30s')           // pick up other people's changes
    ->focusMode(false)      // keep the panel's sidebar on the board page
    ->key('deals');         // where the browser remembers folded and hidden columns
```

![The board in dark mode](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/docs/dark.png)

The board sits on the page like a Filament table: a container with a toolbar whose controls, filters and menus are Filament's own components, styled by the panel's theme. The stylesheet is plain CSS on Filament's colour variables and follows dark mode; restyle the rest with the `--ps-kanban-*` variables on `.ps-kanban` (`--ps-kanban-ring`, `--ps-kanban-col-width`, `--ps-kanban-card-bg`, `--ps-kanban-radius`, …), the scheme Gantt and Spreadsheet share, prefixed so they win over the plugin's stylesheet (`.fi-body .ps-kanban { … }`).

Read more: [Configuration](https://packstub.dev/docs/filament-kanban/configuration), the full fluent API, and [Styling](https://packstub.dev/docs/filament-kanban/styling).

## Documentation

- [Installation](https://packstub.dev/docs/filament-kanban/installation)
- [Columns](https://packstub.dev/docs/filament-kanban/columns)
- [Cards](https://packstub.dev/docs/filament-kanban/cards)
- [Actions](https://packstub.dev/docs/filament-kanban/actions)
- [Swimlanes](https://packstub.dev/docs/filament-kanban/swimlanes)
- [Moves and events](https://packstub.dev/docs/filament-kanban/moves)
- [Filters](https://packstub.dev/docs/filament-kanban/filters)
- [Configuration](https://packstub.dev/docs/filament-kanban/configuration)
- [Styling](https://packstub.dev/docs/filament-kanban/styling)

The same pages live in the [`docs/`](https://github.com/packstub/filament-kanban/tree/main/docs) directory of this repository.

## Testing

```bash
composer test
```

## Changelog

See the [changelog](https://github.com/packstub/filament-kanban/blob/main/CHANGELOG.md).

## Security vulnerabilities

Please e-mail [support@packstub.dev](mailto:support@packstub.dev) rather than opening a public issue.

## Credits

- [Ion Caliman](https://github.com/icaliman)
- [All contributors](https://github.com/packstub/filament-kanban/contributors)

## License

MIT. See the [license file](https://github.com/packstub/filament-kanban/blob/main/LICENSE.md).

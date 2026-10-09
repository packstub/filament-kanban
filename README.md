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

- **[Instant drag and drop](#instant-moves)**: a drop lands at once; if the server refuses, the card slides back with the reason.
- **[Rules on the server](#columns-and-rules)**: columns from an enum, per-user columns, drop rules, read-only columns and WIP limits.
- **[Filament actions on cards](#card-actions)**: edit in a slide-over, delete or run your own actions, and [create in a column](#create-in-a-column).
- **[Column summaries](#summaries-and-limits)**: a sum or an average under each column title, kept current after every move.
- **[Light on big boards](#cards)**: cards are drawn in the browser from JSON, so hundreds of them stay fast.
- **[Search, filters and paging](#search-filters-and-paging)**: instant search, select filters and infinite scroll per column.
- **[Events](https://packstub.dev/docs/filament-kanban/moves)**: a `CardMoved` event, browser events and polling for shared boards.
- **Dark mode and translations**: English, Romanian, Russian and German included.

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

Register it in the resource's `getPages()`: `'kanban' => DealBoard::route('/board')`. Under a resource the `query()` is optional (the board shows the resource's records, tenant and scopes included), `KanbanAction::make()` in the list page's header gives a Table / Board switch, and `$navigationBadgeFromBoard = true` puts the number of cards in the navigation.

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

`Card::make()` with `eyebrow()` (small monospaced line), `title()`, `aside()` (right-aligned: an amount, a date), `badge($label, $color, $condition)`, `meta([...])` (empty parts dropped), `avatar($url, $name)` (initials without a picture), `accent($color)` (a coloured left edge), `url()`, `searchText()` and `actions([...])`.

Read more: [Cards](https://packstub.dev/docs/filament-kanban/cards).

## Search, filters and paging

![Searching the board: the matching cards of every column, with the totals following](https://raw.githubusercontent.com/packstub/art/main/filament-kanban/docs/search.png)

```php
->searchable(['reference', 'company', 'owner.name'])
->filters([
    Filter::make('owner_id')->label('Owner')->options(fn () => User::pluck('name', 'id')->all()),
])
->perColumn(50)
```

Typing filters the loaded cards at once, then the server brings the matching cards of every column. Each column loads its cards in pages as you scroll.

Read more: [Configuration](https://packstub.dev/docs/filament-kanban/configuration#search).

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

The stylesheet is plain CSS on Filament's colour variables and follows dark mode. Restyle it with the `--pk-*` variables on `.pk` (`--pk-ring`, `--pk-col-width`, `--pk-card-bg`, `--pk-radius`, …) from your theme, prefixed so they win over the plugin's stylesheet (`.fi-body .pk { … }`).

Read more: [Configuration](https://packstub.dev/docs/filament-kanban/configuration), the full fluent API.

## Documentation

- [Installation](https://packstub.dev/docs/filament-kanban/installation)
- [Columns](https://packstub.dev/docs/filament-kanban/columns)
- [Cards](https://packstub.dev/docs/filament-kanban/cards)
- [Actions](https://packstub.dev/docs/filament-kanban/actions)
- [Moves and events](https://packstub.dev/docs/filament-kanban/moves)
- [Configuration](https://packstub.dev/docs/filament-kanban/configuration)

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

# Columns

A column is one value of the board's column attribute (`columnAttribute('status')` by default). Columns appear in the order you list them.

```php
->columnAttribute('stage')
->columns([
    Column::make('lead')->color('gray')->icon('heroicon-m-inbox'),
    Column::make('qualified')->label('Qualified')->color('sky')->accepts(['lead'])->description('Budget and timeline confirmed'),
    Column::make('won')->color('emerald')->accepts(['negotiation'])->sortBy('updated_at', 'desc'),
])
```

The label defaults to the name in headline case (`in_review` → "In Review"). A colour is a name (`gray`, `slate`, `red`, `orange`, `amber`, `yellow`, `lime`, `green`, `emerald`, `teal`, `cyan`, `sky`, `blue`, `indigo`, `violet`, `purple`, `fuchsia`, `pink`, `rose`, or your panel's `primary`, `success`, `warning`, `danger`, `info`) or any CSS colour.

## Header

| Method | What it shows |
| --- | --- |
| `label()` | The title. A string is escaped; an `Htmlable` (`new HtmlString('…')`, a rendered view) is drawn as HTML. Takes a closure. |
| `color()` | The dot before the title; it also marks the column in the "Columns" and "Move to" menus. |
| `icon()` | A Heroicon (name or enum) next to the dot. Takes a closure. |
| `description()` | A tooltip on the header: what belongs in the column, who moves cards out. Takes a closure. |

```php
Column::make('review')
    ->label(fn () => new HtmlString('Review <small>(QA)</small>'))
    ->icon('heroicon-m-eye')
    ->description('Awaiting a reviewer; only reviewers move cards out'),
```

## Columns from an enum

Pass a backed enum's class and every case becomes a column, in declaration order. Labels and colours come from Filament's `HasLabel` and `HasColor` when the enum implements them (a Filament palette such as `Color::Amber` is drawn with its 500 shade).

```php
->columnAttribute('stage') // cast to DealStage on the model
->columns(DealStage::class)
```

To adjust some of them, start from `Column::fromEnum()`:

```php
->columns(array_map(fn (Column $column) => match ($column->getName()) {
    'negotiation' => $column->limit(5),
    'lost' => $column->collapsed()->creatable(false),
    default => $column,
}, Column::fromEnum(DealStage::class)))
```

Enum-cast attributes work everywhere: the board reads the backing value, and the default move sets it.

## Who sees and moves what

Every rule takes a boolean or a closure, and is evaluated once per request, never per card.

| Method | What it does |
| --- | --- |
| `visible()` / `hidden()` | Leave the column out for this user: its cards are not loaded, not counted, and nothing moves into or out of it. |
| `droppable()` | Whether this user may drop cards in. |
| `draggable()` | Whether this user may drag cards out. |
| `readOnly()` | Shown, never changed from here: `draggable(false)` and `droppable(false)`. |
| `accepts(['doing'])` | The columns a card may come from. `null` (the default) means anywhere. |

```php
Column::make('review')
    ->accepts(['doing'])
    ->visible(fn () => auth()->user()->isReviewer()),

Column::make('done')
    ->accepts(['review'])
    ->droppable(fn () => auth()->user()->can('ship', Task::class)),
```

While a card is being dragged, columns it may go to are highlighted and the others are dimmed. The server checks the same rules on every move, whatever the browser sent: see [Moves and events](moves.md). For one card that must stay put while the rest of its column moves, see [Locking a card](cards.md#locking-a-card).

## WIP limits

`limit()` caps how many cards a column holds. The header shows `3/5`, turns red when the column is full, and a full column refuses both moves and new cards, on the server as well.

```php
Column::make('doing')->limit(5),
Column::make('review')->limit(fn () => auth()->user()->team->review_capacity),
```

The limit counts every card of the column in the board's query, including the ones the current search or filters hide.

## Summaries

`summarize()` on the board puts a line under every column title: a total, an average, a weighted forecast. The closure gets the column's query (with the current search and filters) and the column, and returns a string, or `null` for nothing.

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

->summarize(fn (Builder $query) => Number::currency($query->sum('amount'), 'USD'))
```

The summaries of both columns are refreshed after every move, and all of them after a search, a filter, an action or a poll. Each summary is one query per column.

## Folding and hiding

Users fold a column to a thin strip (the fold button, or a double click on the header) and hide columns from the "Columns" menu. The browser remembers both per user and per board. `collapsed()` sets the starting state:

```php
Column::make('archived')->collapsed(),
```

## Order

Cards are ordered by the board's `sortBy()`, which a column can override:

```php
->sortBy('due_at')
->columns([
    // …
    Column::make('done')->sortBy('completed_at', 'desc'),
])
```

With `->reorderable('sort')` users also order cards by hand; see [Moves and events](moves.md#reordering).

## Column actions

`Column::actions([...])` puts a `⋯` menu in the column's header with Filament actions on the whole column (archive everything here, export the column), the column's cards injected as `$query`. See [Column actions](actions.md#column-actions).

## Creating in a column

With a [create action](actions.md#create-a-card-in-a-column) on the board, every droppable column gets a "+" in its header. `creatable(false)` leaves a column out:

```php
Column::make('done')->creatable(false),
```

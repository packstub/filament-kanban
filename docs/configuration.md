# Configuration

Everything is configured on the `Board` in your page's `kanban()` method; there is no config file.

```php
public function kanban(Board $board): Board
{
    return $board
        ->query(fn () => Deal::query()->with('owner'))    // every read and move goes through it
        ->columnAttribute('stage')                         // default: 'status'
        ->columns(DealStage::class)                        // or a list of Column::make(…)
        ->card(fn (Deal $deal) => Card::make()->title($deal->company))
        ->sortBy('amount', 'desc')                         // a column can override it
        ->reorderable('sort')                              // manual order, stored as integers
        ->searchable(['reference', 'company', 'owner.name'])
        ->filters([Filter::make('owner_id')->options(fn () => User::pluck('name', 'id')->all())])
        ->summarize(fn (Builder $query) => Number::currency($query->sum('amount'), 'USD'))
        ->cardActions([EditAction::make()->schema([...]), DeleteAction::make()])
        ->cardAction('edit')                               // a click opens the edit modal
        ->createAction(CreateAction::make()->schema([...]))
        ->moveUsing(fn (Deal $deal, string $to, string $from) => $deal->moveTo($to))
        ->perColumn(50)                                    // cards per column before "Load more"
        ->poll('30s')                                      // pick up other people's changes
        ->focusMode(false)                                 // keep the panel's sidebar
        ->key('deals');                                    // where the browser keeps folded / hidden columns
}
```

## Search

`searchable()` lists the attributes the server searches, case-insensitively; a dotted name searches a relationship (`customer.name`). Typing filters the loaded cards at once, then the server's answer brings the matching cards of every column (with the right counts) after a short pause. `/` focuses the search box.

Without `searchable()` the search box is not shown.

## Filters

A `Filter` is a small select in the toolbar. By default a chosen value narrows the query with `where(<name>, <value>)`; give your own with `query()`. Values that are not among the options are ignored, so the browser cannot filter on anything you did not offer.

```php
Filter::make('owner_id')->label('Owner')->options(fn () => User::pluck('name', 'id')->all()),

Filter::make('due')->options(['overdue' => 'Overdue', 'week' => 'Due this week'])
    ->query(fn (Builder $query, string $value) => match ($value) {
        'overdue' => $query->where('due_at', '<', now()),
        'week' => $query->whereBetween('due_at', [now(), now()->endOfWeek()]),
    }),
```

## Paging

Each column loads `perColumn()` cards (50 by default). The rest come in pages as the user scrolls to the bottom of the column; the "Load more" button stays for keyboards.

## Polling

`poll('10s')` (or `'1m'`, or milliseconds) reloads the board every so often, so a shared board picks up other people's changes. It skips a beat while a card is being dragged or saved, while a menu is open, and while the tab is in the background. Columns keep the cards already loaded with "Load more" (up to ten pages), so a poll never scrolls anyone back to the top.

What a load (the first render, a poll, a search, the refresh after an action) costs: one grouped count for the column counts, one more for the WIP totals when a column has a `limit()`, one query per column for its cards, plus your `summarize()` per column. The page loads this once, when the board is first drawn; a re-render of the page afterwards (an action's modal, a form submit) does not read the board again, since the browser keeps it.

## Focus mode

The board page hides the panel's sidebar so the columns get the whole width; a button in the toolbar brings it back (remembered per user). `focusMode(false)` keeps the sidebar.

## Remembered per user

Folded and hidden columns and the sidebar toggle are kept in the browser's local storage under the board's `key()` and the user's id. Give two pages showing the same board the same key to share them.

## Styling

The stylesheet is plain CSS on Filament's colour variables and follows dark mode. Restyle it with the `--pk-*` variables on `.pk` from your theme:

```css
.fi-body .pk {
    --pk-col-width: 20rem;
    --pk-radius: 0.75rem;
    --pk-ring: var(--primary-500);
}
```

The plugin's stylesheet loads after your theme, so prefix your overrides (`.fi-body .pk`).

## Translations

Every string is in `resources/lang/{en,ro,ru,de}/kanban.php`. Publish them to change or add a language:

```bash
php artisan vendor:publish --tag=packstub-kanban-translations
```

## The fluent API

### Board

| Method | Default | |
| --- | --- | --- |
| `query(Builder\|Closure)` | required | The records on the board. |
| `columnAttribute(string)` | `'status'` | The attribute whose value is the column name. |
| `columns(array\|Closure\|class-string)` | `[]` | `Column`s, or a backed enum's class. |
| `card(Closure)` | the key as title | `fn (Model $record): Card`. |
| `sortBy(string, string = 'asc')` | key order | Default order in every column. |
| `reorderable(string = 'sort')` | off | Manual order inside a column. |
| `searchable(array)` | off | Attributes the server searches. |
| `filters(array)` | `[]` | Toolbar selects. |
| `summarize(?Closure)` | off | `fn (Builder $query, Column $column): ?string`. |
| `cardActions(array)` | `[]` | Filament actions in each card's menu. |
| `cardAction(?string)` | off | The card action a click runs. |
| `createAction(?Action)` | off | A "+" on each droppable column. |
| `moveUsing(Closure)` | set and save | `fn (Model $record, string $to, string $from)`. |
| `perColumn(int)` | `50` | Cards per page in a column. |
| `poll(string\|int\|null)` | off | `'10s'`, `'1m'`, milliseconds. |
| `focusMode(bool)` | `true` | Hide the sidebar on the board page. |
| `key(string)` | the page class | Where the browser keeps a user's view. |

### Column

`make($name)`, `fromEnum($enum)`, `label()`, `color()`, `icon()`, `description()`, `visible()`, `hidden()`, `droppable()`, `draggable()`, `readOnly()`, `accepts()`, `limit()`, `creatable()`, `collapsed()`, `sortBy()`. See [Columns](columns.md).

### Card

`make()`, `eyebrow()`, `title()`, `aside()`, `description()`, `badge()`, `meta()`, `due()`, `progress()`, `avatar()`, `accent()`, `url()`, `searchText()`, `actions()`, `locked()`, `draggable()`. See [Cards](cards.md).

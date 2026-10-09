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
        ->persistInUrl(false)                              // leave the search and filters out of the URL
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

A `Filter` is a small select in the toolbar. By default a chosen value narrows the query with `where(<name>, <value>)`; give your own with `query()`. Values that are not among the options are ignored, so the browser cannot filter on anything you did not offer. The closure gets the value as the options spell it: an `int` for `User::pluck('name', 'id')`, a string for `['overdue' => 'Overdue']`. Options given as a closure are resolved once per request, and only once a value is set.

```php
Filter::make('owner_id')->label('Owner')->options(fn () => User::pluck('name', 'id')->all()),

Filter::make('due')->options(['overdue' => 'Overdue', 'week' => 'Due this week'])
    ->query(fn (Builder $query, string $value) => match ($value) {
        'overdue' => $query->where('due_at', '<', now()),
        'week' => $query->whereBetween('due_at', [now(), now()->endOfWeek()]),
    }),
```

### Several at once

`multiple()` turns the select into a popover of checkboxes, with the number of chosen options on the button. The default narrows with `whereIn(<name>, <values>)`; a `query()` closure gets the chosen values as a list, in the order and the type the options spell them. A value outside the options is dropped on its own, the others still apply.

```php
Filter::make('owner_id')->label('Owners')->multiple()->options(fn () => User::pluck('name', 'id')->all()),

Filter::make('tags')->multiple()->options(Tag::pluck('name', 'id')->all())
    ->query(fn (Builder $query, array $values) => $query->whereHas('tags', fn ($q) => $q->whereKey($values))),
```

### On or off

`toggle()` is a single chip with the filter's label and no options: "Overdue", "Assigned to me". There is no default query, so `query()` is required; it gets `true` when the chip is on and is not called at all when it is off.

```php
Filter::make('mine')->label('Assigned to me')->toggle()
    ->query(fn (Builder $query) => $query->where('owner_id', auth()->id())),

Filter::make('overdue')->toggle()
    ->query(fn (Builder $query) => $query->where('due_at', '<', now())),
```

## Search and filters in the URL

The search and the active filters are kept in the page's query string (`?search=acme&filters[owner_id][0]=3&filters[owner_id][1]=7&filters[mine]=1`), replaced in place as they change, so a filtered board can be bookmarked, shared and reloaded; an untouched board keeps a clean URL. The URL is trusted no more than the browser is: a filter the board does not define is ignored, a value outside the options is dropped in the browser and on the server, and a page shared with filters loads as before and then shows the filtered board. `persistInUrl(false)` leaves the URL alone.

The keys are the same for every board, so one URL-persisted board per page: with two boards on a page, or a page that uses `?search=` for something else, `persistInUrl(false)` on the others.

## Paging

Each column loads `perColumn()` cards (50 by default). The rest come in pages as the user scrolls to the bottom of the column; the "Load more" button stays for keyboards.

## Polling

`poll('10s')` (or `'1m'`, or milliseconds) reloads the board every so often, so a shared board picks up other people's changes. It skips a beat while a card is being dragged or saved, while a menu is open, and while the tab is in the background. Columns keep the cards already loaded with "Load more" (up to ten pages), so a poll never scrolls anyone back to the top.

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
| `filters(array)` | `[]` | `Filter`s in the toolbar. |
| `persistInUrl(bool)` | `true` | Keep the search and filters in the query string. |
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

`make($name)`, `fromEnum($enum)`, `label()`, `color()`, `visible()`, `hidden()`, `droppable()`, `draggable()`, `readOnly()`, `accepts()`, `limit()`, `creatable()`, `collapsed()`, `sortBy()`. See [Columns](columns.md).

### Card

`make()`, `eyebrow()`, `title()`, `aside()`, `badge()`, `meta()`, `avatar()`, `accent()`, `url()`, `searchText()`, `actions()`. See [Cards](cards.md).

### Filter

| Method | Default | |
| --- | --- | --- |
| `make(string $name)` | required | The attribute the default query narrows, and the key in the URL. |
| `label(string\|Closure\|null)` | from the name | `owner_id` reads "Owner". |
| `options(array\|Closure)` | `[]` | `[value => label]`; the only values the server accepts. |
| `query(Closure)` | `where` / `whereIn` | `fn (Builder $query, string\|int\|array\|true $value)`, the value as the options spell it; required for `toggle()`. |
| `multiple(bool = true)` | `false` | Several options at once; the query gets a list. |
| `toggle(bool = true)` | `false` | An on/off chip without options; the query gets `true`. |

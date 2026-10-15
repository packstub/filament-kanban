# Filters

A `Filter` narrows the board's query from the toolbar. The fields are Filament's own (its select, searchable or not, its date picker, its checkbox), and the same `Filter` class with the same methods is in [Filament Gantt](https://packstub.dev/docs/filament-gantt) and [Filament Spreadsheet](https://packstub.dev/docs/filament-spreadsheet), so a filter written for one works in the others.

```php
use Packstub\Kanban\Filter;

->filters([
    Filter::select('owner_id')->label('Owner')->options(fn () => User::pluck('name', 'id')->all()),
    Filter::select('tags')->multiple()->options(Tag::pluck('name', 'id')->all()),
    Filter::boolean('is_hot')->label('Hot deal'),
    Filter::toggle('mine')->label('Assigned to me')->query(fn (Builder $query) => $query->where('owner_id', auth()->id())),
    Filter::dateRange('due_at'),
])
```

Every filter is applied on the server only: whatever the browser or the URL sends is checked again. A plain select takes one of its options and nothing else, a multiple one drops unknown values one by one, a searchable select takes any key (its values come from a search, the query decides what matches), a date range takes real calendar dates only.

## Select

`Filter::select('owner_id')` (or `Filter::make()`, the same) is one of the options. By default a chosen value narrows the query with `where(<name>, <value>)`; give your own with `query()`, which gets the value as a string, the option's key as the browser sends it (`"3"` for `User::pluck('name', 'id')`). Options given as a closure are resolved once per request, and on a refresh only once a value is set.

```php
Filter::select('owner_id')->label('Owner')->options(fn () => User::pluck('name', 'id')->all()),

Filter::select('due')->options(['overdue' => 'Overdue', 'week' => 'Due this week'])
    ->query(fn (Builder $query, string $value) => match ($value) {
        'overdue' => $query->where('due_at', '<', now()),
        'week' => $query->whereBetween('due_at', [now(), now()->endOfWeek()]),
    }),
```

### Several at once

`multiple()` lets the user pick several options; the default narrows with `whereIn(<name>, <values>)`, a `query()` closure gets the chosen values as a list of strings, in the options' order. A value outside the options is dropped on its own, the others still apply.

```php
Filter::select('tags')->label('Tags')->multiple()->options(Tag::pluck('name', 'id')->all())
    ->query(fn (Builder $query, array $values) => $query->whereHas('tags', fn ($q) => $q->whereKey($values))),
```

### Searchable

`searchable()` lets the user type to find an option, for a long list (every user, every customer). With `preload()` (the default) the options are sent with the board and the search runs on the server over their labels, or through `getSearchResultsUsing()`; `preload(false)` sends nothing until the user types. `getOptionLabelUsing()` names a value that is not among the listed options (one restored from the URL), `optionsLimit()` caps a list or a search (50 by default).

```php
Filter::select('customer_id')->label('Customer')->searchable()->preload(false)
    ->getSearchResultsUsing(fn (string $search) => Customer::where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id')->all())
    ->getOptionLabelUsing(fn (string $value) => Customer::find($value)?->name),
```

## Boolean

`Filter::boolean('is_hot')` is a Yes / No select. By default it narrows `<name>` to true or false; a `query()` closure gets a bool.

```php
Filter::boolean('is_hot')->label('Hot deal'),

Filter::boolean('assigned')->query(fn (Builder $query, bool $on) => $on ? $query->whereNotNull('owner_id') : $query->whereNull('owner_id')),
```

## Toggle

`Filter::toggle('mine')` is one checkbox with the filter's label and no options: "Overdue", "Assigned to me". There is no default query, so `query()` is required (checked on the first render, not when someone clicks); it gets `true` when the toggle is on and is not called at all when it is off.

```php
Filter::toggle('mine')->label('Assigned to me')
    ->query(fn (Builder $query) => $query->where('owner_id', auth()->id())),
```

## Date range

`Filter::dateRange('due_at')` is a From and an Until date picker; either side may be left open. By default the range narrows `<name>` to the days between, the last day included, without wrapping the column in a function (its index stays in use); a `query()` closure gets `['from' => ?string, 'until' => ?string]` as `Y-m-d`. The pickers display `config('packstub-kanban.date_format')` (`M j, Y` by default) and start their weeks on `first_day_of_week`.

```php
Filter::dateRange('due_at')->label('Due'),

Filter::dateRange('created_at')->query(fn (Builder $query, array $range) => $query
    ->when($range['from'], fn ($q, $from) => $q->where('created_at', '>=', $from))
    ->when($range['until'], fn ($q, $until) => $q->where('created_at', '<', Carbon::parse($until)->addDay()))),
```

## Where the filters are shown

`filtersLayout()` on the board, or `filters_layout` in `config/packstub-kanban.php` for every board:

```php
use Packstub\Kanban\Enums\FiltersLayout;

->filtersLayout(FiltersLayout::Dropdown)      // behind a funnel button, with the count of active filters (the default, as a table's)
->filtersLayout(FiltersLayout::Toolbar)       // inline in the toolbar, after the search box
->filtersLayout(FiltersLayout::AboveContent)  // on a row of their own between the toolbar and the columns
```

The dropdown and the row have a **Reset** link once any filter is active. Without filters nothing is rendered, not even the funnel.

## Search and filters in the URL

The search and the active filters are kept in the page's query string (`?search=acme&filters[owner_id][0]=3&filters[owner_id][1]=7&filters[mine]=1&filters[due_at][from]=2026-01-01`), replaced in place as they change, so a filtered board can be bookmarked, shared and reloaded; an untouched board keeps a clean URL. The URL is trusted no more than the browser is: a filter the board does not define is ignored, a value outside the options is dropped in the browser and on the server, a date that is not a calendar day (`2026-02-30`) never reaches the query. The page is drawn filtered from the start, with no second request; a board drawn later (lazy, deferred) reads the URL in the browser and refreshes once. `persistInUrl(false)` leaves the URL alone.

The keys are the same for every board, so one URL-persisted board per page: with two boards on a page, or a page that uses `?search=` for something else, `persistInUrl(false)` on the others. A board only rewrites its own keys (`search` when it is searchable, `filters[...]` for the filters it defines) and leaves every other parameter alone.

## The fluent API

| Method | Default | |
| --- | --- | --- |
| `select(string $name)`, `make()` | | One option; the attribute the default query narrows, and the key in the URL. |
| `boolean(string $name)` | | A Yes / No select; the query gets a bool. |
| `toggle(string $name)` | | One checkbox; `query()` is required and gets `true`. |
| `dateRange(string $name)` | | From / Until pickers; the query gets `['from' => ?string, 'until' => ?string]`. |
| `label(string\|Closure\|null)` | from the name | `owner_id` reads "Owner", `due_at` "Due". |
| `options(array\|Closure)` | `[]` | `[value => label]`; the only values a plain select accepts. |
| `query(Closure)` | `where` / `whereIn` / a range | `fn (Builder $query, $value)`: a string, a list of strings for `multiple()`, a bool, `true`, or the range. |
| `multiple(bool = true)` | `false` | Several options at once. |
| `searchable(bool\|Closure = true)` | `false` | Type to find an option. |
| `preload(bool = true)` | `true` | Send the options with the board, or wait for a search. |
| `getSearchResultsUsing(?Closure)` | the labels | `fn (string $search): array`. |
| `getOptionLabelUsing(?Closure)` | the options | `fn (string $value): ?string`, for a value outside the listed options. |
| `optionsLimit(int)` | `50` | The most options a list or a search shows. |

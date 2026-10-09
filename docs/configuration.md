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
        ->bulkActions([BulkAction::make('assign')->action(fn (Collection $records) => ...)])
        ->swimlanes('owner_id', fn () => User::all()->map(fn ($u) => Lane::make($u->id)->label($u->name))->all())
        ->createAction(CreateAction::make()->schema([...]))
        ->moveUsing(fn (Deal $deal, string $to, string $from) => $deal->moveTo($to))
        ->perColumn(50)                                    // cards per column before "Load more"
        ->poll('30s')                                      // pick up other people's changes
        ->broadcast()                                      // or at once with Laravel Echo (queued; or broadcastNow())
        ->undo(10)                                         // "Undo" on the move notification, for 10 s
        ->focusMode(false)                                 // keep the panel's sidebar
        ->density('compact')                               // tighter cards by default; a toggle lets users choose
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

## Realtime with Echo

With [Laravel Echo](https://laravel.com/docs/broadcasting) on the page, `broadcast()` replaces polling: after every change (a move, a card action, a create, a column action) the server broadcasts a `Packstub\Kanban\Events\BoardChanged` event on a private channel, and every other tab showing the board reloads at once. The tab that made the change recognises its own token in the event and ignores it; a burst of events is one request; a reload never interrupts a card in the air (a change that arrives mid-drag is loaded once the drop has settled).

```php
->broadcast()                                            // private channel "kanban.<key slug>", event ".kanban.changed"
->broadcast(fn () => 'tenant.'.tenant()->id.'.kanban')   // your own channel name (a string or a closure)
->broadcast('deals', 'deals.changed')                    // and your own event name
->broadcastNow()                                         // sent during the request instead of through the queue
```

**The channel.** By default the channel is `kanban.` + a slug of the board's `key()`: with the default key, the page class, `App\Filament\Pages\Deals` gives `kanban.app-filament-pages-deals` (dots, colons and backslashes become `-`, so a `{board}` route parameter matches it and `project.1.5` and `project.15` stay apart); `->key('deals')` gives `kanban.deals`. Authorise it in `routes/channels.php` as any private channel, or realtime silently does nothing (Echo gets a 403):

```php
use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('kanban.{board}', fn (User $user) => $user->can('viewAny', Deal::class));
```

The default channel is shared by everyone who may see the page, tenants included: a change in one tenant's board reloads the other tenants' tabs (wasted requests, each through its own scoped query), and the event's payload (the record's id, the column names) reaches their browsers. On a multi-tenant app give the channel the tenant and authorise it as such:

```php
->broadcast(fn () => 'tenant.'.Filament::getTenant()->getKey().'.kanban')

Broadcast::channel('tenant.{tenant}.kanban', fn (User $user, string $tenant) => $user->belongsToTenant($tenant));
```

**The queue.** `BoardChanged` implements `ShouldBroadcast`: it is queued, on your `QUEUE_CONNECTION`, once the transaction that made the change commits (`DB::afterCommit()`, so an action with `databaseTransaction()` never broadcasts a change that is rolled back), so a worker must run (`php artisan queue:work`; with `QUEUE_CONNECTION=database` and no worker nothing is ever sent). `BROADCAST_CONNECTION` picks the broadcaster (`reverb`, `pusher`, `ably`; `log` is handy while setting up). `broadcastNow()` skips the queue: the event goes out during the request, which is simpler and fine for a small team, but a broadcaster that is down then costs every move its timeout. Either way, a failing broadcast never fails the change: it is saved by then, the failure is reported to your exception handler, and the move answers "ok".

The event is broadcast as `kanban.changed` (Echo's `.kanban.changed`, the leading dot added for you if you leave it out) and carries `board` (the key), `id`, `from`, `to` and `origin`, so your own listener (`Echo.private('kanban.deals').listen('.kanban.changed', ...)`) can use it too. A board ignores events for another board key on its channel, and the tabs in the background (or with a menu open) wait until they are shown (or it closes) to reload. Leaving the page stops this board's listener only; the channel stays for the rest of the page. Without Echo on the page the option does nothing; `poll()` keeps working, and both may be set. `CardMoved` is unchanged: it stays the event your server-side listeners use.

## Focus mode

The board page hides the panel's sidebar so the columns get the whole width; a button in the toolbar brings it back (remembered per user). `focusMode(false)` keeps the sidebar.

## Density

A toggle in the toolbar switches between **Comfortable** and **Compact**: compact cards have smaller padding, one-line titles and a tighter column gap, for boards with hundreds of cards. `density('compact')` makes compact the default; a user's choice is remembered and wins over it.

## Keyboard

Every card is reachable with Tab: a card with a `url()` as a link, one the click action opens as a button. Inside a column, Arrow Up and Arrow Down move between the visible cards, Home and End jump to the first and the last. Enter opens the card (its url, or the click action); Shift+F10 or the menu key opens the card's menu, where the arrows walk the actions and the "Move to…" targets and Escape returns to the card. `/` focuses the search box.

Columns are regions labelled with their name and count, and a visually hidden live region announces what the browser did: "Moved to Done", "Loaded 50 more cards", "12 cards match" after a search (a refused move is read from its notification). The counted ones take plural forms like any Laravel translation (`:count card|:count cards`, `{0}`/`[2,*]` ranges too), chosen for the app's locale. After a move or a refusal made from the keyboard, focus stays on the card (a mouse drop leaves it alone); when the card went to a column that is not on screen (a folded one, another tab on a narrow screen), that column's fold button or tab takes it. On narrow screens the column tabs follow the usual pattern: Arrow Left and Right, Home and End switch columns.

## Narrow screens

Below 64rem (a phone, a narrow tablet) the board shows one column at a time, at full width, picked from a tab bar above it (name, dot and count per column). A sideways swipe over the board goes to the next or previous column; the selected column is remembered with the other preferences. Dragging works inside the column; "Move to…" in the card menu moves a card to another column. The desktop layout above the breakpoint is unchanged.

## Remembered per user

Folded and hidden columns, folded lanes, the sidebar toggle, the density and the selected column on narrow screens are kept in the browser's local storage under the board's `key()` and the user's id. Give two pages showing the same board the same key to share them.

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
| `bulkActions(array)` | `[]` | Actions on the selection bar; `BulkAction` gets `$records`. |
| `selectable(bool\|Closure)` | with bulk actions | Let users select cards and move them together. |
| `swimlanes(?string, array\|Closure\|class-string\|null)` | off | Rows by an attribute: `Lane`s, an enum, or derived from the data. |
| `createAction(?Action)` | off | A "+" on each droppable column. |
| `moveUsing(Closure)` | set and save | `fn (Model $record, string $to, string $from, ?string $lane)`. |
| `perColumn(int)` | `50` | Cards per page in a column. |
| `poll(string\|int\|null)` | off | `'10s'`, `'1m'`, milliseconds. |
| `undo(bool\|int)` | `5` | "Undo" on the notification after a move, for this many seconds; `false` for none. |
| `broadcast(string\|Closure\|null, string, bool)` | off | Reload the other tabs through Echo: the private channel (default `kanban.<key slug>`), the event name (`.kanban.changed`), queued unless `now: true`. |
| `broadcastNow(string\|Closure\|null, string)` | off | `broadcast()` during the request, without the queue. |
| `focusMode(bool)` | `true` | Hide the sidebar on the board page. |
| `density(string)` | `'comfortable'` | `'compact'` for tighter cards by default; the user's toggle wins. |
| `key(string)` | the page class | Where the browser keeps a user's view. |

### Column

`make($name)`, `fromEnum($enum)`, `label()`, `color()`, `icon()`, `description()`, `visible()`, `hidden()`, `droppable()`, `draggable()`, `readOnly()`, `accepts()`, `limit()`, `creatable()`, `collapsed()`, `sortBy()`, `actions()`. See [Columns](columns.md) and [Column actions](actions.md#column-actions).

### Lane

`make($value)`, `fromEnum($enum)`, `label()`, `color()`, `collapsed()`. See [Swimlanes](swimlanes.md).

### Card

`make()`, `eyebrow()`, `title()`, `aside()`, `description()`, `badge()`, `meta()`, `due()`, `progress()`, `avatar()`, `accent()`, `url()`, `searchText()`, `actions()`, `locked()`, `draggable()`. See [Cards](cards.md).

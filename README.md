# Filament Kanban

A fast, simple Kanban board for Filament v4 and v5.

- **Instant.** A drop moves the card at once and asks the server afterwards. If the server says no, the card slides back and the reason is shown. The board never re-renders on a move.
- **Per-user columns.** Show a column only to the people who work in it, and decide who may drop into it or drag out of it.
- **Drop rules.** Say which columns a card may come from; impossible targets dim while you drag, and the server enforces the same rules.
- **Focus mode.** The board page hides the panel's sidebar so the columns get the whole width; one button brings the menu back.
- **Simple cards.** Shape a card in one closure: a reference, a title, an amount, badges and a line of meta. Cards are drawn in the browser from JSON, so hundreds of them stay light.
- Instant search as you type (refined on the server), select filters, folding and hiding columns (remembered per user), "Move to…" for touch and keyboard, paging per column, dark mode.

## Install

```bash
composer require packstub/filament-kanban
php artisan filament:assets
```

No config, no migrations. The board uses Filament's bundled SortableJS and ships plain CSS on your panel's palette, so there is nothing to add to your theme.

## A board page

```php
use App\Models\Task;
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Filter;
use Packstub\Kanban\Pages\KanbanResourcePage;

class TaskBoard extends KanbanResourcePage // or Packstub\Kanban\Pages\KanbanPage for a standalone page
{
    protected static string $resource = TaskResource::class;

    public function kanban(Board $board): Board
    {
        return $board
            ->query(fn () => Task::query()->with('project'))
            ->columnAttribute('status')
            ->sortBy('due_at')
            ->searchable(['title', 'project.name'])
            ->filters([
                Filter::make('project_id')->options(fn () => Project::pluck('name', 'id')->all()),
            ])
            ->columns([
                Column::make('todo')->label('To do')->color('sky'),
                Column::make('doing')->color('amber')->accepts(['todo']),
                Column::make('review')->color('violet')
                    ->accepts(['doing'])
                    ->visible(fn () => auth()->user()->isReviewer()),
                Column::make('done')->color('emerald')
                    ->accepts(['review'])
                    ->droppable(fn () => auth()->user()->can('ship'))
                    ->collapsed()
                    ->sortBy('updated_at', 'desc'),
            ])
            ->card(fn (Task $task) => Card::make()
                ->eyebrow($task->key)
                ->title($task->title)
                ->aside($task->due_at?->format('M j'))
                ->badge('blocked', 'red', $task->is_blocked)
                ->meta([$task->project->name, $task->assignee?->name])
                ->url(TaskResource::getUrl('view', ['record' => $task])));
    }
}
```

Register it in the resource's `getPages()` like any other page: `'board' => TaskBoard::route('/board')`.

## Moves

By default a move sets the column attribute and saves. Give your own logic to `moveUsing()`; throw to refuse the move and the card goes back with the exception's message:

```php
->moveUsing(function (Task $task, string $to, string $from) {
    if ($to === 'done' && $task->openChecks()->exists()) {
        throw new \RuntimeException('Close the open checks first.');
    }

    $task->update(['status' => $to]);
})
```

Before your code runs, the server checks the same rules the browser shows: the record is in the board's `query()`, both columns are visible to this user, the source is `draggable`, the target is `droppable` and `accepts` the source. Scope the query (tenant, team, date window) and every read and every move goes through it.

To let users order cards inside a column, add `->reorderable('sort')`, with an integer `sort` column. The order of the loaded cards is stored after each drop.

## Columns

| Method | What it does |
| --- | --- |
| `label()`, `color()` | Title and dot colour: a name (`gray`, `sky`, `amber`, `orange`, `violet`, `emerald`, `red`, …) or any CSS colour. |
| `visible()` / `hidden()` | Leave the column out for this user: its cards are not loaded and nothing moves into or out of it. |
| `accepts([...])` | The columns a card may come from. `null` (default) means anywhere. |
| `droppable()`, `draggable()`, `readOnly()` | Who may drop in and drag out. |
| `collapsed()` | Start folded to a thin strip. Users fold, unfold and hide columns themselves; the browser remembers it. |
| `sortBy()` | This column's order, overriding the board's. |

Every rule takes a closure and is evaluated once per request, never per card.

## Cards

`Card::make()` with `eyebrow()` (small monospaced line), `title()`, `aside()` (right-aligned: an amount, a date), `badge($label, $color, $condition)`, `meta([...])` (empty parts dropped), `accent($color)` (a coloured left edge), `url()` and `searchText()` (extra words the instant search matches).

## Board options

`perColumn(50)` cards per column before "Load more", `focusMode(false)` to keep the sidebar, `key()` to name where the browser keeps a user's folded and hidden columns.

## Styling

The stylesheet is plain CSS on Filament's colour variables and follows dark mode. Restyle it with the `--pk-*` variables on `.pk` (`--pk-ring`, `--pk-col-width`, `--pk-card-bg`, `--pk-radius`, …) from your theme. The plugin's stylesheet loads after your theme, so prefix your overrides (`.fi-body .pk { … }`).

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).

# Swimlanes

Swimlanes group the board into rows by a second attribute: the assignee, the priority, the project, the sprint. Each lane is a row of the same columns; the column headers stay at the top, and the whole board scrolls.

```php
->swimlanes('assignee_id', fn () => User::query()->orderBy('name')->get()->map(fn (User $user) => Lane::make($user->id)->label($user->name))->all())
```

## Lanes from the data

With only the attribute, the lanes are the attribute's distinct values on the board, in first-seen order of the board's `sortBy()`, labelled in headline case (`in_review` → "In Review"). Cards whose value is `null` go to an "Unassigned" lane at the end.

```php
->swimlanes('priority')
```

Derived lanes follow the data: a lane with no cards left disappears on the next refresh, and the first card with a new value adds one. An empty board shows the "Unassigned" lane alone, so there is somewhere to drop. When the attribute is cast to a backed enum on the model, the labels and colours come from its `HasLabel` and `HasColor`.

Derive lanes from an attribute with a handful of values (a priority, a type, a team). A column loads all its lanes in one query (a window function; MySQL before 8 has none and gets a query per lane), but every lane is a row on screen and in the counts; past 50 derived values the rest fold into an "Other" lane. For many values, define the lanes you want to show.

## Defined lanes

Pass `Lane`s to fix the rows, their order, labels and colours, or a backed enum's class for one lane per case (labels and colours from Filament's `HasLabel` and `HasColor`, like [columns](columns.md#columns-from-an-enum)):

```php
use Packstub\Kanban\Lane;

->swimlanes('priority', [
    Lane::make('high')->label('High')->color('red'),
    Lane::make('normal')->label('Normal'),
    Lane::make('low')->label('Low')->color('gray')->collapsed(),
])

->swimlanes('priority', Priority::class)
```

A closure returning the lanes is evaluated once per request, so it can depend on the user. Labels and colours take closures too.

Cards whose value is not among the defined lanes (and `null` ones) are shown in an "Unassigned" lane, added while the board holds such cards. Define `Lane::make('')` (the empty value, `Lane::UNASSIGNED`) yourself to give it a label or keep it always visible. A card keeps its own value while it moves inside that row; a drop *into* the "Unassigned" row writes `null`. The "Other" row of capped derived lanes holds many values, so it takes no card from another row (`Lane::droppable(false)`); a card already in it moves between columns as usual.

A card's lane is read from the attribute's stored value, the one the counts group by, not from its cast: a `boolean` cast gives the lanes `'0'` and `'1'`.

## What a drop does

A card dropped in another lane gets that lane's value as well as the column's: the default save sets both attributes (`null` for the unassigned lane). A drop in another lane of the same column is a move too, with the same `CardMoved` event (`$event->lane`), browser event and `kanbanMoved()` hook. A move inside the card's own row leaves the lane attribute alone, whatever value it holds, so a card in "Unassigned" or "Other" keeps its real value.

Every column rule applies unchanged. A WIP limit counts the whole column across lanes; the column header's count is the column's, each lane header shows its own.

`moveUsing()` receives the lane change fourth, when the board has swimlanes and the closure takes a fourth parameter: `null` means the lane did not change (a move inside the row, a "Move to…" from the card's menu, a bulk move), `''` (`Lane::UNASSIGNED`) means the card moved to the unassigned lane, anything else is the new lane's value.

```php
->moveUsing(function (Task $task, string $to, string $from, ?string $lane) {
    $task->update([
        'status' => $to,
        ...($lane === null ? [] : ['assignee_id' => $lane === '' ? null : $lane]),
    ]);
})
```

A closure with three parameters (written before the board had lanes) is left as it was: the board sets the lane attribute itself and saves it after the closure, and a lane change inside one column does not call the closure at all.

`CardMoved::$lane` carries the same value.

## Folding and paging

Users fold a lane to its header (the chevron, or a double click on the header); the board remembers it per user with the other [view preferences](configuration.md#remembered-per-user). `Lane::collapsed()` sets the starting state.

Each cell (a column in a lane) loads `perColumn()` cards and the rest in pages as the user scrolls, so a long lane never crowds out the others.

## Reading the lanes

`Board::getLanes()` returns the resolved `Lane`s (`null` without swimlanes), each column's state carries `counts` per lane next to its `count`, and every card carries `lane`.

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

Derived lanes follow the data: a lane with no cards left disappears on the next refresh, and the first card with a new value adds one.

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

Cards whose value is not among the defined lanes (and `null` ones) are shown in an "Unassigned" lane, added while the board holds such cards. Define `Lane::make('')` (the empty value, `Lane::UNASSIGNED`) yourself to give it a label or keep it always visible.

## What a drop does

A card dropped in another lane gets that lane's value as well as the column's: the default save sets both attributes (`null` for the unassigned lane). A drop in another lane of the same column is a move too, with the same `CardMoved` event (`$event->lane`), browser event and `kanbanMoved()` hook.

Every column rule applies unchanged. A WIP limit counts the whole column across lanes; the column header's count is the column's, each lane header shows its own.

`moveUsing()` receives the lane fourth, when the board has swimlanes:

```php
->moveUsing(function (Task $task, string $to, string $from, ?string $lane) {
    $task->update([
        'status' => $to,
        'assignee_id' => $lane === null ? $task->assignee_id : ($lane === '' ? null : $lane),
    ]);
})
```

`$lane` is `null` when the move did not carry one (a "Move to…" from the card's menu keeps the card's lane).

## Folding and paging

Users fold a lane to its header (the chevron, or a double click on the header); the board remembers it per user with the other [view preferences](configuration.md#remembered-per-user). `Lane::collapsed()` sets the starting state.

Each cell (a column in a lane) loads `perColumn()` cards and the rest in pages as the user scrolls, so a long lane never crowds out the others.

## Reading the lanes

`Board::getLanes()` returns the resolved `Lane`s (`null` without swimlanes), each column's state carries `counts` per lane next to its `count`, and every card carries `lane`.

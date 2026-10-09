# Moves and events

## What happens on a drop

1. The card moves in the browser at once, the column counts change, and the card is marked as pending.
2. The browser asks the server (`kanbanMove`, a renderless Livewire call: nothing is re-rendered).
3. The server loads the record through the board's `query()` and checks the rules: both columns are visible to this user, the card is not [locked](cards.md#locking-a-card), the source is `draggable`, the target is `droppable`, `accepts` the source, and is not at its [WIP limit](columns.md#wip-limits).
4. Your move logic runs (by default: set the column attribute and save).
5. The server answers with the card as it looks now; the card flashes once. If anything refused the move, the card slides back and the reason is shown as a notification.

"Move to…" in the card's menu does the same without dragging, for touch screens and keyboards.

## Your own move logic

`moveUsing()` replaces the default save. Throw `MoveRejected` to refuse the move: the card goes back and the message is shown.

```php
use Packstub\Kanban\Exceptions\MoveRejected;

->moveUsing(function (Task $task, string $to, string $from) {
    if ($to === 'done' && $task->openChecks()->exists()) {
        throw new MoveRejected('Close the open checks first.');
    }

    $task->update(['status' => $to, 'completed_at' => $to === 'done' ? now() : null]);
})
```

With [swimlanes](swimlanes.md) the closure receives the lane change fourth: `fn (Task $task, string $to, string $from, ?string $lane)`, `null` when the lane did not change, `''` for a move to the unassigned lane (the default save writes `null`), otherwise the new lane's value.

Throw `Packstub\Kanban\Exceptions\MoveRejected` to refuse with a message you want shown as-is. Any other exception (the database's, a bug in the closure) is reported to your exception handler and the user sees "The move did not go through.": an exception's text, a query with its bindings say, belongs in the log, not in a notification. `Board::move()` itself lets such an exception propagate, so a call of your own sees it.

## Moving several cards

"Move to…" on the [selection bar](actions.md#bulk-selection) moves every selected card in one request (`kanbanMoveMany`; a selection stops at 500 cards, and the server refuses a larger one as a whole). Each card goes through the same rules and `moveUsing()` as a single move, in its own save: the cards that went through stay moved and dispatch `CardMoved`, the refused ones slide back in the browser with one notification listing the reasons. The call as a whole is not a transaction; a refusal never undoes the other cards.

With [swimlanes](swimlanes.md), a bulk move keeps each card's lane.

## Reordering

`->reorderable('sort')` lets users order cards inside a column (and choose the position when moving to another one). The attribute is an integer column; after each drop the whole target column is renumbered: the cards the browser shows get 1, 2, 3… top to bottom, and every other card of the column (beyond the loaded pages) follows them in its current order, so the two never collide. A card without a position yet (a new record) sorts after the positioned ones, never to the top. Only the positions that change are written. Ids from other columns, or outside the query, are ignored.

The move and the new order are saved in one transaction: a refused move (`MoveRejected`) or a failure while storing the order leaves both untouched. The cards beyond the loaded page are not rewritten one by one: they are left alone when their positions already sit above the loaded ones and shifted up in one statement when they collide, and cards without a position are numbered a few hundred per statement.

```php
->reorderable('sort')
->sortBy('created_at') // the order among cards with the same position
```

## Events

After a card changes column (not after reordering inside one), the board dispatches a Laravel event. It goes out once the move's transaction commits; when `move()` runs inside a transaction of your own it waits for that one, and a rollback (a `MoveRejected`, a failure while saving) never fires it. A listener that throws is reported; the move stays saved:

```php
use Packstub\Kanban\Events\CardMoved;

Event::listen(function (CardMoved $event) {
    activity()->performedOn($event->record)->log("Moved from {$event->from} to {$event->to}");
});
```

`$event->board` is the board's `key()` (the page class by default), handy when several boards share a model. With swimlanes, `$event->lane` is the lane the card changed to (`''` for the unassigned lane, `null` when the lane did not change), and a drop in another lane of the same column dispatches the event too, with `$from === $to`.

On the page itself, override `kanbanMoved()` to react in the component:

```php
protected function kanbanMoved(string $id, string $to, array $card): void
{
    Notification::make()->title('Moved')->success()->send();
}
```

In the browser, a `kanban-card-moved` event bubbles up from the board with `{ id, from, to, card }` (and `lane` with swimlanes, also for a drop in another lane of the same column):

```html
<div x-on:kanban-card-moved.window="console.log($event.detail)"></div>
```

## Refreshing from outside

Dispatch `packstub-kanban-refresh` (from Livewire, `$this->dispatch('packstub-kanban-refresh')`, or from JavaScript on `window`) and the board reloads its cards with the current search and filters. Actions run from the board do it for you.

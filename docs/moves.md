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

Throw `Packstub\Kanban\Exceptions\MoveRejected` to refuse with a message you want shown as-is. Any other exception (the database's, a bug in the closure) is reported to your exception handler and the user sees "The move did not go through.": an exception's text, a query with its bindings say, belongs in the log, not in a notification. `Board::move()` itself lets such an exception propagate, so a call of your own sees it.

## Reordering

`->reorderable('sort')` lets users order cards inside a column (and choose the position when moving to another one). The attribute is an integer column; after each drop the positions of the target column's loaded cards are stored as 1, 2, 3… Ids from other columns, or outside the query, are ignored.

```php
->reorderable('sort')
->sortBy('created_at') // the order among cards with the same position
```

## Events

After a card changes column (not after reordering inside one), the board dispatches a Laravel event:

```php
use Packstub\Kanban\Events\CardMoved;

Event::listen(function (CardMoved $event) {
    activity()->performedOn($event->record)->log("Moved from {$event->from} to {$event->to}");
});
```

`$event->board` is the board's `key()` (the page class by default), handy when several boards share a model.

On the page itself, override `kanbanMoved()` to react in the component:

```php
protected function kanbanMoved(string $id, string $to, array $card): void
{
    Notification::make()->title('Moved')->success()->send();
}
```

In the browser, a `kanban-card-moved` event bubbles up from the board with `{ id, from, to, card }`:

```html
<div x-on:kanban-card-moved.window="console.log($event.detail)"></div>
```

## Refreshing from outside

Dispatch `packstub-kanban-refresh` (from Livewire, `$this->dispatch('packstub-kanban-refresh')`, or from JavaScript on `window`) and the board reloads its cards with the current search and filters. Actions run from the board do it for you.

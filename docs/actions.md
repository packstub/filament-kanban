# Actions

The board speaks Filament actions: the ones you already use on tables (`EditAction`, `ViewAction`, `DeleteAction`, your own `Action::make()`) work on cards, with their forms, modals, slide-overs, confirmations and notifications.

Actions need a component with Filament's action support. `KanbanPage` and `KanbanResourcePage` have it; for your own Livewire component see [Installation](installation.md#a-board-in-any-livewire-component).

## Card actions

`cardActions()` lists the actions in each card's menu (the `⋯` button, above "Move to"):

```php
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;

->cardActions([
    EditAction::make()->schema(DealResource::fields())->slideOver(),
    Action::make('hot')
        ->label('Mark as hot')
        ->icon(Heroicon::OutlinedFire)
        ->action(fn (Deal $record) => $record->update(['is_hot' => true])),
    DeleteAction::make(),
])
```

The card's record is the action's record: inject `$record` (or `Deal $record`) anywhere, as on a table. It is loaded through the board's `query()`, so an action can never reach a record the user cannot see on the board; for such a card the action is hidden and refused.

Once an action has run, the board reloads its cards (keeping the search and filters), so an edited card shows its new content, a deleted one disappears and a card whose column changed moves.

Your own `hidden()`, `visible()`, `authorize()` and `disabled()` rules keep working, with the record injected. The menu is built once for the whole board (never per card), so an action a card should not offer at all is left out with `Card::actions()`:

```php
->card(fn (Deal $deal) => Card::make()
    ->title($deal->company)
    ->actions($deal->is_locked ? ['view'] : ['view', 'edit', 'delete']))
```

## Open a card in a modal

`cardAction()` names the card action a click on the card runs, instead of following its `url()`:

```php
->cardActions([EditAction::make()->schema([...])->slideOver()])
->cardAction('edit')
```

A click opens the slide-over; a middle click still opens the card's URL in a new tab when it has one (Ctrl/⌘- and Shift-click [select](#bulk-selection) the card). A drag never counts as a click.

## Create a card in a column

`createAction()` puts a "+" in the header of every droppable column. The action's form opens, and the new record gets that column: the board sets the column attribute in the data it saves.

```php
use Filament\Actions\CreateAction;

->createAction(
    CreateAction::make()
        ->label('New deal')
        ->schema([
            TextInput::make('company')->required(),
            TextInput::make('amount')->numeric()->prefix('$'),
            Select::make('owner_id')->relationship('owner', 'name'),
        ])
        ->slideOver(),
)
```

The model is taken from the board's query unless you set one with `->model()`. The column attribute must be fillable on the model (or add it yourself in `->using()`). `mutateDataUsing()` still runs first, before the board adds the column.

The "+" is left out of columns that are hidden, not droppable, marked `creatable(false)`, or full (a [WIP limit](columns.md#wip-limits)), and the server refuses a create into any of them.

## Bulk selection

Ctrl/⌘-click selects a card (and deselects it), Shift-click selects every card between the last selected one and this one in the same column, and a checkbox on each card does the same on hover and on touch screens. While cards are selected a bar takes the toolbar's place: the count, "Move to…", the board's bulk actions and Clear (or Escape). A selected card is not opened on click.

`bulkActions()` lists the actions on that bar. A `Packstub\Kanban\Actions\BulkAction` is a Filament action over the selection, the way a table's `BulkAction` is over its selected rows: inject `$records` (an Eloquent collection) or `Builder $query`.

```php
use Illuminate\Database\Eloquent\Collection;
use Packstub\Kanban\Actions\BulkAction;

->bulkActions([
    BulkAction::make('assign')
        ->label('Assign to me')
        ->icon(Heroicon::OutlinedUser)
        ->action(fn (Collection $records) => $records->each->update(['owner_id' => auth()->id()])),
    BulkAction::make('delete')
        ->color('danger')
        ->requiresConfirmation()
        ->action(fn (Collection $records) => $records->each->delete()),
])
```

The records are loaded through the board's `query()`, so a selection never reaches a record the user cannot see on the board; ids outside it are dropped, and the action is hidden when nothing selected is on the board. The board reloads once the action has run. A plain `Action` works on the bar too, with the selected ids in `$arguments['kanbanRecords']`.

"Move to…" offers the columns every selected card may go to, by the same rules as a drag; see [Moving several cards](moves.md#moving-several-cards).

## Header actions

A board page is a Filament page: `getHeaderActions()` adds buttons above the board as usual (a link to the list view, an import, a "New" button that opens a full form).

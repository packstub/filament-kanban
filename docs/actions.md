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

A click opens the slide-over; Ctrl/⌘-click and middle-click still open the card's URL in a new tab when it has one. A drag never counts as a click.

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

## Column actions

`Column::actions()` puts a `⋯` menu in the column's header: "Archive everything in Done", "Export this column", "Assign all to me". Use `ColumnAction::make()` (a Filament `Action` with three more injections): `$query` is the column's cards as the user sees them (the board's `query()`, the column, and the search and filters the user has on), `$column` is the `Column`, `$board` the `Board`.

```php
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Packstub\Kanban\Actions\ColumnAction;
use Packstub\Kanban\Column;

Column::make('lost')->actions([
    ColumnAction::make('archive')
        ->label(fn (Column $column) => 'Archive all in '.$column->getLabel())
        ->icon(Heroicon::OutlinedArchiveBox)
        ->requiresConfirmation()
        ->action(fn (Builder $query) => $query->update(['archived_at' => now()])),
    ColumnAction::make('export')
        ->action(fn (Builder $query) => Excel::download(new DealsExport($query), 'lost.xlsx')),
]),
```

The action is bound to its column when the board registers it, under the name `column:<column>:<action>`, so two columns may both have an `archive`. One action object may be given to several columns: the first keeps it, the others get a copy, so such a shared action must take `$query` and `$column` as closure arguments rather than reach them through `$this` (a closure bound in a subclass's `setUp()` keeps pointing at the original). An action of its own is used as it is, `$this` included. Modals, forms, confirmations, `authorize()`, `hidden()`, `visible()` and `disabled()` work as on any action, with the column injected; an action the app hides is left out of the menu. The server resolves the column from the request again and never trusts the browser past the column's name and its current search and filters (validated as on a refresh): an action of a hidden column is hidden and refused. The board reloads once the action has run.

## Header actions

A board page is a Filament page: `getHeaderActions()` adds buttons above the board as usual (a link to the list view, an import, a "New" button that opens a full form).

# Installation

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- Filament 4 or 5

## 1. Require the package

```bash
composer require packstub/filament-kanban
php artisan filament:assets
```

There is no config file and there are no migrations. The board uses the SortableJS that Filament already ships and a small stylesheet built on your panel's colour variables, so there is nothing to add to your theme. The script and the stylesheet load only on pages that show a board.

Run `php artisan filament:assets` again after every update of the package: Filament copies the assets into `public/`.

## 2. Add a board page

A board lives on a page. `KanbanResourcePage` sits next to a resource's list; `KanbanPage` is a standalone panel page. Both ask for one method, `kanban()`:

```php
namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Pages\KanbanResourcePage;

class TaskBoard extends KanbanResourcePage
{
    protected static string $resource = TaskResource::class;

    public function kanban(Board $board): Board
    {
        return $board
            ->query(fn () => Task::query())
            ->columnAttribute('status')
            ->columns([
                Column::make('todo')->label('To do')->color('sky'),
                Column::make('doing')->color('amber'),
                Column::make('done')->color('emerald'),
            ])
            ->card(fn (Task $task) => Card::make()
                ->eyebrow($task->key)
                ->title($task->title)
                ->url(TaskResource::getUrl('edit', ['record' => $task])));
    }
}
```

Register it in the resource like any other page:

```php
public static function getPages(): array
{
    return [
        'index' => ListTasks::route('/'),
        'kanban' => TaskBoard::route('/board'),
    ];
}
```

Under a resource the `query()` line is optional: see [A board under a resource](#a-board-under-a-resource).

A standalone `KanbanPage` is discovered with your other pages. It is a normal Filament page, so `getHeaderActions()`, navigation and authorization work as usual.

## 3. The query decides what is on the board

`query()` is the single source of records. Every read (cards, counts, search, summaries) and every write (moves, card actions) goes through it, so scope it there: a tenant, a team, the current user's projects, a date window.

```php
->query(fn () => Task::query()->whereBelongsTo(Filament::getTenant())->with('assignee'))
```

A card that falls out of the query (someone else archived it, the user lost access) can no longer be moved or acted on: the server answers "This card is no longer on the board".

## A board under a resource

`KanbanResourcePage` knows its resource, so three things come for free.

**The query.** Leave `query()` out and the board shows the resource's records: `getEloquentQuery()`, evaluated on every call, so the panel's tenant and the resource's own scopes (soft deletes, a `where` you added there) apply to the board as they do to the table. Set `query()` to narrow or eager-load; it replaces the default, so start it from the resource's query when you want to keep its scopes:

```php
public function kanban(Board $board): Board
{
    return $board
        ->query(fn () => TaskResource::getEloquentQuery()->with('assignee'))
        ->columns(TaskStatus::class)
        ->card(fn (Task $task) => Card::make()->title($task->title));
}
```

**The Table / Board switch.** Register the board page and put `KanbanAction` in the list page's header: a "Board" button on the table, and the board page shows a "Table" button back to the list.

```php
// TaskResource
public static function getPages(): array
{
    return [
        'index' => Pages\ListTasks::route('/'),
        'kanban' => Pages\TaskBoard::route('/board'),
    ];
}

// Pages\ListTasks
use Packstub\Kanban\Actions\KanbanAction;

protected function getHeaderActions(): array
{
    return [KanbanAction::make(), CreateAction::make()];
}
```

`KanbanAction` links to the resource's `kanban` page, or its `board` page when there is no `kanban`; `->page('pipeline')` names another, and the button is hidden when the resource has no such page. It is a Filament action, so `->label()`, `->icon()`, `->color()` and `->outlined()` work as usual.

The "Table" button is the board page's default `getHeaderActions()`, shown when the resource has an `index` page. Override the method to add to it (`[TableAction::make(), CreateAction::make()]`) or to drop it (`[]`). Outside a resource page, both actions take the resource explicitly: `KanbanAction::make()->resource(TaskResource::class)`.

**The navigation badge.** The sidebar shows the resource, not its pages, so the badge goes on the resource. The `HasKanbanNavigationBadge` trait makes the resource's badge the number of cards the user may see on its board page (the first `KanbanResourcePage` in `getPages()`), counted in one grouped query over the visible columns; no badge when the board is empty:

```php
use Packstub\Kanban\Concerns\HasKanbanNavigationBadge;

class TaskResource extends Resource
{
    use HasKanbanNavigationBadge;
}
```

For your own badge, `TaskBoard::getBoardCount()` gives the number. It builds the page without `mount()`, so a `kanban()` that reads state set in `mount()` or a public property counts without it.

A standalone `KanbanPage` is its own navigation item: `protected static bool $navigationBadgeFromBoard = true;` on the page shows the same count. Off (the default), building the navigation never touches the database.

## A board in any Livewire component

The pages are thin: the board itself lives in the `InteractsWithKanban` trait. Put it on any Livewire component and include the view:

```php
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use Packstub\Kanban\Board;
use Packstub\Kanban\Concerns\InteractsWithKanban;

class ProjectBoard extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithKanban;
    use InteractsWithSchemas;

    public Project $project;

    public function kanban(Board $board): Board
    {
        return $board->query(fn () => $this->project->tasks()->getQuery())->key('project-board');
        // …
    }

    public function render()
    {
        return view('livewire.project-board'); // @include('packstub-kanban::board') + <x-filament-actions::modals />
    }
}
```

`HasActions` and `HasSchemas` are only needed for [card and create actions](actions.md).

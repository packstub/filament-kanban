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
        'board' => TaskBoard::route('/board'),
    ];
}
```

A standalone `KanbanPage` is discovered with your other pages. It is a normal Filament page, so `getHeaderActions()`, navigation and authorization work as usual.

## 3. The query decides what is on the board

`query()` is the single source of records. Every read (cards, counts, search, summaries) and every write (moves, card actions) goes through it, so scope it there: a tenant, a team, the current user's projects, a date window.

```php
->query(fn () => Task::query()->whereBelongsTo(Filament::getTenant())->with('assignee'))
```

A card that falls out of the query (someone else archived it, the user lost access) can no longer be moved or acted on: the server answers "This card is no longer on the board".

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

<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Concerns\InteractsWithKanban;
use Packstub\Kanban\Filter;

/** A plain Livewire component holding a board, the way an app page would. */
class TaskBoard extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithKanban;
    use InteractsWithSchemas;

    /** Columns the "user" may see; null = all. */
    public static ?array $visible = null;

    public static bool $canShip = true;

    public static ?int $doingLimit = null;

    public function kanban(Board $board): Board
    {
        return $board
            ->query(fn () => Task::query()->with('project'))
            ->sortBy('priority', 'desc')
            ->searchable(['title', 'project.name'])
            ->filters([
                Filter::make('project_id')->multiple()->options(fn () => Project::pluck('name', 'id')->all()),
                Filter::make('urgent')->toggle()->query(fn ($query) => $query->where('priority', '>', 5)),
            ])
            ->columns(array_map(fn (string $name) => Column::make($name)
                ->visible(static::$visible === null || in_array($name, static::$visible, true))
                ->when($name === 'doing', fn (Column $c) => $c->accepts(['todo'])->limit(static::$doingLimit))
                ->when($name === 'done', fn (Column $c) => $c->accepts(['doing'])->droppable(fn () => static::$canShip)->creatable(false)),
                ['todo', 'doing', 'done']))
            ->summarize(fn ($query) => $query->sum('priority').' pts')
            ->cardActions([
                EditAction::make()->schema([TextInput::make('title')->required()]),
                DeleteAction::make()->hidden(fn (Task $record) => $record->priority > 5),
                Action::make('bump')->action(fn (Task $record) => $record->increment('priority')),
            ])
            ->cardAction('edit')
            ->createAction(CreateAction::make()->schema([TextInput::make('title')->required()]))
            ->card(fn (Task $task) => Card::make()->title($task->title)->meta([$task->project?->name])->url('/tasks/'.$task->id));
    }

    public function render(): string
    {
        return '<div>@include(\'packstub-kanban::board\') <x-filament-actions::modals /></div>';
    }
}

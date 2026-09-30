<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Livewire\Component;
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Concerns\InteractsWithKanban;

/** A plain Livewire component holding a board, the way an app page would. */
class TaskBoard extends Component
{
    use InteractsWithKanban;

    /** Columns the "user" may see; null = all. */
    public static ?array $visible = null;

    public static bool $canShip = true;

    public function kanban(Board $board): Board
    {
        return $board
            ->query(fn () => Task::query()->with('project'))
            ->sortBy('priority', 'desc')
            ->searchable(['title', 'project.name'])
            ->columns(array_map(fn (string $name) => Column::make($name)
                ->visible(static::$visible === null || in_array($name, static::$visible, true))
                ->when($name === 'doing', fn (Column $c) => $c->accepts(['todo']))
                ->when($name === 'done', fn (Column $c) => $c->accepts(['doing'])->droppable(fn () => static::$canShip)),
                ['todo', 'doing', 'done']))
            ->card(fn (Task $task) => Card::make()->title($task->title)->meta([$task->project?->name])->url('/tasks/'.$task->id));
    }

    public function render(): string
    {
        return '<div>@include(\'packstub-kanban::board\')</div>';
    }
}

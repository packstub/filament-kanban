<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use Packstub\Kanban\Board;
use Packstub\Kanban\Column;
use Packstub\Kanban\Concerns\InteractsWithKanban;

/** A board on a Livewire component without Filament's action system. */
class PlainBoard extends Component implements HasSchemas
{
    use InteractsWithKanban;
    use InteractsWithSchemas;

    public function kanban(Board $board): Board
    {
        return $board
            ->query(fn () => Task::query())
            ->columns([Column::make('todo'), Column::make('doing')])
            ->cardActions([EditAction::make()])
            ->createAction(CreateAction::make());
    }

    public function render(): string
    {
        return '<div>@include(\'packstub-kanban::board\')</div>';
    }
}

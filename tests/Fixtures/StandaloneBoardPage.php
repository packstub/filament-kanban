<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Packstub\Kanban\Board;
use Packstub\Kanban\Column;
use Packstub\Kanban\Pages\KanbanPage;

/** A standalone panel page with its badge from the board. */
class StandaloneBoardPage extends KanbanPage
{
    protected static bool $navigationBadgeFromBoard = true;

    public function kanban(Board $board): Board
    {
        return $board
            ->query(fn () => Task::query())
            ->columns([Column::make('todo'), Column::make('doing'), Column::make('done')->hidden()]);
    }
}

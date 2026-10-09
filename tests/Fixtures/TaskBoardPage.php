<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Pages\KanbanResourcePage;

/** The resource's board page: no query() of its own, badge from the board. */
class TaskBoardPage extends KanbanResourcePage
{
    protected static string $resource = TaskResource::class;

    protected static bool $navigationBadgeFromBoard = true;

    /** Set by a test to give the board its own query. */
    public static ?\Closure $query = null;

    public function kanban(Board $board): Board
    {
        return $board
            ->when(static::$query, fn (Board $b) => $b->query(static::$query))
            ->columns([Column::make('todo'), Column::make('doing'), Column::make('done')->hidden()])
            ->card(fn (Task $task) => Card::make()->title($task->title));
    }
}

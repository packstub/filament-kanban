<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Packstub\Kanban\Concerns\HasKanbanNavigationBadge;

/** A resource whose query is scoped (tasks without a project), with its board's count as the navigation badge. */
class TaskResource extends Resource
{
    use HasKanbanNavigationBadge;

    protected static ?string $model = Task::class;

    /** Set by a test: the resource's list orders by title, newest first. */
    public static bool $ordered = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('project_id')->when(static::$ordered, fn (Builder $query) => $query->orderByDesc('title'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'kanban' => TaskBoardPage::route('/board'),
        ];
    }
}

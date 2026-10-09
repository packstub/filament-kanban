<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/** A resource whose query is scoped (tasks without a project), to see the board inherit it. */
class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('project_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'kanban' => TaskBoardPage::route('/board'),
        ];
    }
}

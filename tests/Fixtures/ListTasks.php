<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Filament\Resources\Pages\ListRecords;
use Packstub\Kanban\Actions\KanbanAction;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [KanbanAction::make()];
    }
}

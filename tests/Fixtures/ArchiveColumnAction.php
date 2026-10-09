<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Packstub\Kanban\Actions\ColumnAction;

/** A column action of its own, wired in setUp() through $this, the way Filament's prebuilt actions are. */
class ArchiveColumnAction extends ColumnAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Archive all')
            ->action(fn () => $this->getColumnQuery()->update(['status' => 'archived']));
    }
}

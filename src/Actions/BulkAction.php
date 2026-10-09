<?php

namespace Packstub\Kanban\Actions;

use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use LogicException;

/**
 * A Filament action over the board's selected cards, the way a table's BulkAction
 * is over its selected rows: inject `$records` (an Eloquent collection) or
 * `Builder $query`. The board resolves them through its own query, so a user
 * never reaches a record not on their board (see KanbanActions).
 */
class BulkAction extends Action
{
    protected ?Closure $kanbanRecords = null;

    protected ?Closure $kanbanRecordsQuery = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bulk();
        $this->accessSelectedRecords();
    }

    /** @param  Closure(): EloquentCollection  $records */
    public function records(Closure $records): static
    {
        $this->kanbanRecords = $records;

        return $this;
    }

    /** @param  Closure(): Builder  $query */
    public function recordsQuery(Closure $query): static
    {
        $this->kanbanRecordsQuery = $query;

        return $this;
    }

    public function getSelectedRecords(): EloquentCollection|Collection|LazyCollection
    {
        $records = $this->kanbanRecords ? ($this->kanbanRecords)() : new EloquentCollection;

        $this->totalSelectedRecordsCount = $records->count();
        $this->successfulSelectedRecordsCount = $this->totalSelectedRecordsCount;

        return $records;
    }

    public function getSelectedRecordsQuery(): Builder
    {
        return $this->kanbanRecordsQuery
            ? ($this->kanbanRecordsQuery)()
            : throw new LogicException("The bulk action [{$this->getName()}] is not on a board: register it with Board::bulkActions().");
    }
}

<?php

namespace Packstub\Kanban\Pages;

use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Packstub\Kanban\Actions\TableAction;
use Packstub\Kanban\Board;
use Packstub\Kanban\Concerns\InteractsWithKanban;

/**
 * A resource page holding one board, next to the resource's list. Implement
 * kanban(Board $board); without a query() the board shows the resource's records.
 * For a badge on the resource's navigation item, see HasKanbanNavigationBadge.
 */
abstract class KanbanResourcePage extends Page
{
    use InteractsWithKanban;

    protected string $view = 'packstub-kanban::pages.kanban';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * Without a query() of its own, the board shows the resource's records:
     * getEloquentQuery() (tenancy and the resource's scopes included, evaluated on every
     * call), limited to the parent record for a nested resource, as its list is. The
     * resource's own order is dropped: the board orders its columns itself.
     */
    protected function kanbanDefaults(Board $board): Board
    {
        if (! $board->hasQuery()) {
            $board->query(function () {
                $query = static::getResource()::getEloquentQuery()->reorder();
                $parent = $this->getParentRecord();

                return $parent ? static::getResource()::scopeEloquentQueryToParent($query, $parent) : $query;
            });
        }

        return $board;
    }

    /** A "Table" link back to the list by default; override to add to it or drop it. */
    protected function getHeaderActions(): array
    {
        return [TableAction::make()];
    }

    public function getExtraBodyAttributes(): array
    {
        return $this->getKanban()->hasFocusMode()
            ? ['class' => trim(($this->extraBodyAttributes['class'] ?? '').' pk-focus')] + $this->extraBodyAttributes
            : $this->extraBodyAttributes;
    }
}

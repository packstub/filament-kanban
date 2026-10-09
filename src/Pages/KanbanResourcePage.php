<?php

namespace Packstub\Kanban\Pages;

use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Packstub\Kanban\Actions\TableAction;
use Packstub\Kanban\Board;
use Packstub\Kanban\Concerns\HasNavigationBadgeFromBoard;
use Packstub\Kanban\Concerns\InteractsWithKanban;

/**
 * A resource page holding one board, next to the resource's list. Implement
 * kanban(Board $board); without a query() the board shows the resource's records.
 */
abstract class KanbanResourcePage extends Page
{
    use HasNavigationBadgeFromBoard;
    use InteractsWithKanban;

    protected string $view = 'packstub-kanban::pages.kanban';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * The board, on the resource's query (getEloquentQuery(): tenancy and the
     * resource's scopes included, evaluated on every call) unless kanban() set one.
     */
    public function getKanban(): Board
    {
        if ($this->kanbanBoard) {
            return $this->kanbanBoard;
        }

        $board = $this->kanban(Board::make()->key(static::class));

        if (! $board->hasQuery()) {
            $board->query(fn () => static::getResource()::getEloquentQuery());
        }

        return $this->kanbanBoard = $board;
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

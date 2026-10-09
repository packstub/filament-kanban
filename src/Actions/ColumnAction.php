<?php

namespace Packstub\Kanban\Actions;

use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Packstub\Kanban\Board;
use Packstub\Kanban\Column;

/**
 * A Filament action in a column's header menu. Its closures get the column's cards
 * as `$query` (the board's query, filtered to the column, with the search and
 * filters the user has on), the `Column` as `$column` and the `Board` as `$board`,
 * the way a card action gets `$record`. The board sets both when it registers the
 * action; the browser only names the column, and only the search and filters it shows.
 */
class ColumnAction extends Action
{
    protected ?Board $board = null;

    protected ?Column $column = null;

    public function board(Board $board): static
    {
        $this->board = $board;

        return $this;
    }

    public function column(Column $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function getBoard(): ?Board
    {
        return $this->board;
    }

    public function getColumn(): ?Column
    {
        return $this->column;
    }

    /** The column's cards, with the search and filters the browser sent (validated by the board as on a refresh). */
    public function getColumnQuery(): Builder
    {
        $arguments = $this->getArguments();
        $search = $arguments['kanbanSearch'] ?? '';
        $filters = $arguments['kanbanFilters'] ?? [];

        return $this->board->getColumnQuery(
            $this->column->getName(),
            is_string($search) ? $search : '',
            is_array($filters) ? $filters : [],
        );
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'query' => [$this->getColumnQuery()],
            'column' => [$this->column],
            'board' => [$this->board],
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        return match ($parameterType) {
            Builder::class => [$this->getColumnQuery()],
            Column::class => [$this->column],
            Board::class => [$this->board],
            default => parent::resolveDefaultClosureDependencyForEvaluationByType($parameterType),
        };
    }
}

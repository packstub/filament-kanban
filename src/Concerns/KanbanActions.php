<?php

namespace Packstub\Kanban\Concerns;

use Closure;
use Filament\Actions\Action;
use Packstub\Kanban\Actions\BulkAction;
use Packstub\Kanban\Actions\ColumnAction;
use Packstub\Kanban\Board;
use SplObjectStorage;

/**
 * Registers the board's card and create actions with Filament's action system, on
 * components that have one (panel pages do). Filament calls cacheKanbanActions()
 * while booting, for every trait whose name ends in "Actions".
 *
 * The browser mounts them with the card (`kanbanRecord`), the selection
 * (`kanbanRecords`) or the column (`kanbanColumn`) as an argument; everything else
 * is resolved here again, through the board's query and column rules, whatever the
 * browser sent.
 */
trait KanbanActions
{
    public function cacheKanbanActions(): void
    {
        $board = $this->getKanban();

        // An action object given to several columns (or already a card or create action) is
        // copied for every column but the first, before any of them is touched; an action of
        // its own is used as it is, so closures bound in setUp() keep their $this.
        $seen = new SplObjectStorage;

        foreach ([...$board->getCardActions(), ...$board->getBulkActions(), ...array_filter([$board->getCreateAction()])] as $action) {
            $seen->attach($action);
        }

        foreach ($board->getAllColumns() as $column) {
            $column->actions(array_map(function (Action $action) use ($seen) {
                if ($seen->contains($action)) {
                    $copy = clone $action;

                    if ($copy instanceof ColumnAction) {
                        $copy->copiedFrom($action);
                    }

                    return $copy;
                }

                $seen->attach($action);

                return $action;
            }, $column->getActions()));
        }

        foreach ($board->getCardActions() as $action) {
            $action->record(fn () => $board->findRecord($action->getArguments()['kanbanRecord'] ?? null));

            static::kanbanChainHidden($action, fn () => $board->findRecord($action->getArguments()['kanbanRecord'] ?? null) === null);
            $this->kanbanRefreshAfter($action);

            $this->cacheAction($action);
        }

        foreach ($board->getBulkActions() as $action) {
            $ids = fn () => (array) ($action->getArguments()['kanbanRecords'] ?? []);

            if ($action instanceof BulkAction) {
                // $records and the query select the same rows: the cleaned, capped ids.
                $action->records(fn () => $board->findRecords($ids()));
                $action->recordsQuery(fn () => $board->baseQuery()->whereKey(Board::selectionIds($ids())));
            }

            if ($action->getModel(withDefault: false) === null) {
                $action->model($board->getModel());
            }

            static::kanbanChainHidden($action, fn () => ! $board->hasRecords($ids()));
            $this->kanbanRefreshAfter($action);

            $this->cacheAction($action);
        }

        if ($action = $board->getCreateAction()) {
            if ($action->getModel(withDefault: false) === null) {
                $action->model($board->getModel());
            }

            static::kanbanChainHidden($action, fn () => ! $board->canCreateIn($action->getArguments()['kanbanColumn'] ?? null));

            $mutate = static::kanbanProperty($action, 'mutateDataUsing');

            $action->mutateDataUsing(fn (array $data) => [
                ...($mutate ? $action->evaluate($mutate, ['data' => $data]) : $data),
                $board->getColumnAttribute() => $action->getArguments()['kanbanColumn'] ?? null,
            ]);

            $this->kanbanRefreshAfter($action);

            $this->cacheAction($action);
        }

        // Column actions are cached as `column:<column>:<name>`, so two columns may share a
        // name; hidden columns' actions are registered too and hidden, like a card off the board.
        foreach ($board->getAllColumns() as $column) {
            foreach ($column->getActions() as $action) {
                if ($action instanceof ColumnAction) {
                    $action->board($board)->column($column);
                }

                if (static::kanbanProperty($action, 'label') === null) {
                    $action->label($action->getLabel());
                }

                $action->name('column:'.$column->getName().':'.$action->getName());

                static::kanbanChainHidden($action, fn () => ($action->getArguments()['kanbanColumn'] ?? null) !== $column->getName()
                    || $board->getColumn($column->getName()) === null);
                $this->kanbanRefreshAfter($action);

                $this->cacheAction($action);
            }
        }
    }

    /** Once the action has run, the browser reloads the board (keeping its search and filters) and the other tabs are told. */
    protected function kanbanRefreshAfter(Action $action): void
    {
        $after = static::kanbanProperty($action, 'after');

        $action->after(function () use ($action, $after) {
            $result = $after ? $action->evaluate($after) : null;

            $this->dispatch('packstub-kanban-refresh');

            $arguments = $action->getArguments();
            $this->getKanban()->broadcastChange(
                id: is_scalar($arguments['kanbanRecord'] ?? null) ? (string) $arguments['kanbanRecord'] : null,
                origin: is_string($arguments['kanbanOrigin'] ?? null) ? $arguments['kanbanOrigin'] : null,
            );

            return $result;
        });
    }

    /** Hide the action when $hidden says so, keeping whatever hidden() rule the app gave it. */
    protected static function kanbanChainHidden(Action $action, Closure $hidden): void
    {
        $original = static::kanbanProperty($action, 'isHidden');

        $action->hidden(fn () => $hidden() || (bool) $action->evaluate($original));
    }

    protected static function kanbanProperty(Action $action, string $property): mixed
    {
        return (fn () => $this->{$property})->call($action);
    }
}

<?php

namespace Packstub\Kanban\Concerns;

use Filament\Actions\Contracts\HasActions;
use Livewire\Attributes\Renderless;
use Packstub\Kanban\Board;
use Packstub\Kanban\Exceptions\MoveRejected;

/**
 * The server side of a board, for any Livewire component (the plugin's pages use it).
 * Every call is renderless: the browser already shows the result, so the server
 * only answers with data and never re-renders the board.
 */
trait InteractsWithKanban
{
    use KanbanActions;

    protected ?Board $kanbanBoard = null;

    abstract public function kanban(Board $board): Board;

    public function getKanban(): Board
    {
        return $this->kanbanBoard ??= $this->kanban(Board::make()->key(static::class));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, int|array<string, int>>  $loaded  cards already shown per column (per lane with swimlanes), reloaded as many
     * @return array{columns: list<array<string, mixed>>, lanes?: list<array<string, mixed>>}
     */
    #[Renderless]
    public function kanbanRefresh(string $search = '', array $filters = [], array $loaded = []): array
    {
        $board = $this->getKanban();

        return [
            'columns' => $board->getState($search, $filters, $loaded),
            ...($board->hasLanes() ? ['lanes' => $this->kanbanLanes($board)] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    #[Renderless]
    public function kanbanMore(string $column, int $offset, string $search = '', array $filters = [], ?string $lane = null): array
    {
        return $this->getKanban()->getCards($column, $search, $filters, $offset, lane: $lane);
    }

    /**
     * A refused move answers with its reason. Anything else that goes wrong while
     * saving (a database error, a bug in moveUsing()) is reported and answered with
     * a generic message: an exception's text (a query with its bindings, say) is
     * for the log, never for a notification.
     *
     * @param  list<string>|null  $order
     * @param  array<string, mixed>  $filters  the board's current ones, for the column summaries
     * @return array{ok: bool, card?: array<string, mixed>, summaries?: array<string, string|null>, message?: string}
     */
    #[Renderless]
    public function kanbanMove(string $id, string $to, ?array $order = null, string $search = '', array $filters = [], ?string $lane = null): array
    {
        $board = $this->getKanban();
        $from = $board->findRecord($id)?->getAttribute($board->getColumnAttribute());
        $from = $from instanceof \BackedEnum ? $from->value : $from;

        try {
            $card = $board->move($id, $to, $order, $lane);
        } catch (MoveRejected $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => __('packstub-kanban::kanban.failed')];
        }

        $this->kanbanMoved($id, $to, $card);

        return [
            'ok' => true,
            'card' => $card,
            'summaries' => $board->getSummaries(array_filter([(string) $from, $to]), $search, $filters),
        ];
    }

    /**
     * Move several cards at once (the selection bar's "Move to"). Each card goes
     * through Board::move() with the same rules, in its own save: the ones that
     * went through stay moved, the refused ones answer with their reason so the
     * browser puts them back. With swimlanes, $lane moves them all into that lane;
     * null keeps each card's lane.
     *
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $filters
     * @return array{ok: bool, moved: list<array<string, mixed>>, refused: list<array{id: string, message: string}>, summaries: array<string, string|null>}
     */
    #[Renderless]
    public function kanbanMoveMany(array $ids, string $to, string $search = '', array $filters = [], ?string $lane = null): array
    {
        $board = $this->getKanban();
        $moved = [];
        $refused = [];
        $columns = [$to];

        foreach (array_unique(array_map('strval', $ids)) as $id) {
            $from = $board->findRecord($id)?->getAttribute($board->getColumnAttribute());
            $columns[] = (string) ($from instanceof \BackedEnum ? $from->value : $from);

            try {
                $card = $board->move($id, $to, null, $lane);
            } catch (MoveRejected $e) {
                $refused[] = ['id' => $id, 'message' => $e->getMessage()];

                continue;
            } catch (\Throwable $e) {
                report($e);
                $refused[] = ['id' => $id, 'message' => __('packstub-kanban::kanban.failed')];

                continue;
            }

            $this->kanbanMoved($id, $to, $card);
            $moved[] = $card;
        }

        return [
            'ok' => $moved !== [],
            'moved' => $moved,
            'refused' => $refused,
            'summaries' => $board->getSummaries(array_values(array_filter($columns)), $search, $filters),
        ];
    }

    /**
     * Hook after a successful move (log it, notify someone…).
     *
     * @param  array<string, mixed>  $card
     */
    protected function kanbanMoved(string $id, string $to, array $card): void {}

    /** @return array<string, mixed> */
    public function getKanbanConfig(): array
    {
        $board = $this->getKanban();

        // Card and create actions need Filament's action system on the component.
        $actions = $this instanceof HasActions;

        return [
            'key' => 'kanban:'.($board->getKey() ?? static::class).':'.(auth()->id() ?? 'guest'),
            'columns' => $board->getState(),
            'lanes' => $board->hasLanes() ? $this->kanbanLanes($board) : null,
            'perColumn' => $board->getPerColumn(),
            'reorderable' => $board->isReorderable(),
            'searchable' => $board->isSearchable(),
            'filters' => array_map(fn ($f) => ['name' => $f->getName(), 'label' => $f->getLabel(), 'options' => collect($f->getOptions())->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()->all()], $board->getFilters()),
            'focus' => $board->hasFocusMode(),
            'poll' => $board->getPoll(),
            'cardActions' => array_map(fn ($action) => [
                'name' => $action->getName(),
                'label' => $action->getLabel(),
                'icon' => ($icon = $action->getIcon() ?? $action->getGroupedIcon()) ? \Filament\Support\generate_icon_html($icon)?->toHtml() : null,
                'color' => is_string($color = $action->getColor()) ? $color : null,
            ], $actions ? $board->getCardActions() : []),
            'cardAction' => $board->getCardAction(),
            'bulkActions' => array_map(fn ($action) => [
                'name' => $action->getName(),
                'label' => $action->getLabel(),
                'icon' => ($icon = $action->getIcon() ?? $action->getGroupedIcon()) ? \Filament\Support\generate_icon_html($icon)?->toHtml() : null,
                'color' => is_string($color = $action->getColor()) ? $color : null,
            ], $actions ? $board->getBulkActions() : []),
            'createAction' => $actions && ($create = $board->getCreateAction()) ? ['name' => $create->getName(), 'label' => $create->getLabel()] : null,
            'i18n' => __('packstub-kanban::kanban'),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function kanbanLanes(Board $board): array
    {
        return array_map(fn ($lane) => $lane->toArray(), $board->getLanes() ?? []);
    }
}

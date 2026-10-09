<?php

namespace Packstub\Kanban\Concerns;

use Filament\Actions\Contracts\HasActions;
use Livewire\Attributes\Locked;
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

    /** @var array<string, mixed>|null */
    protected ?array $kanbanConfig = null;

    /** Whether the board has been sent with its state: later renders send `columns: null`. */
    #[Locked]
    public bool $kanbanDrawn = false;

    abstract public function kanban(Board $board): Board;

    public function getKanban(): Board
    {
        return $this->kanbanBoard ??= $this->kanban(Board::make()->key(static::class));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, int|array<string, int>>  $loaded  cards already shown per column (per lane with swimlanes), reloaded as many
     * @return array{columns: list<array<string, mixed>>, icons: array<string, string>, lanes?: list<array<string, mixed>>}
     */
    #[Renderless]
    public function kanbanRefresh(string $search = '', array $filters = [], array $loaded = []): array
    {
        $board = $this->getKanban();

        return [
            'columns' => $board->getState($search, $filters, $loaded),
            'icons' => $board->getIcons(),
            ...($board->hasLanes() ? ['lanes' => $this->kanbanLanes($board)] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{cards: list<array<string, mixed>>, icons: array<string, string>}
     */
    #[Renderless]
    public function kanbanMore(string $column, int $offset, string $search = '', array $filters = [], ?string $lane = null): array
    {
        $board = $this->getKanban();

        return ['cards' => $board->getCards($column, $search, $filters, $offset, lane: $lane), 'icons' => $board->getIcons()];
    }

    /**
     * A refused move answers with its reason. Anything else that goes wrong while
     * saving (a database error, a bug in moveUsing()) is reported and answered with
     * a generic message: an exception's text (a query with its bindings, say) is
     * for the log, never for a notification.
     *
     * @param  list<string>|null  $order
     * @param  array<string, mixed>  $filters  the board's current ones, for the column summaries
     * @return array{ok: bool, card?: array<string, mixed>, summaries?: array<string, string|null>, icons?: array<string, string>, message?: string}
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
            'icons' => $board->getIcons(),
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
     * @return array{ok: bool, moved: list<array<string, mixed>>, refused: list<array{id: string, message: string}>, summaries: array<string, string|null>, message?: string}
     */
    #[Renderless]
    public function kanbanMoveMany(array $ids, string $to, string $search = '', array $filters = [], ?string $lane = null): array
    {
        $board = $this->getKanban();
        $moved = [];
        $refused = [];
        $columns = [$to];

        if (Board::selectionTooLarge($ids)) {
            return ['ok' => false, 'moved' => [], 'refused' => [], 'summaries' => [], 'message' => __('packstub-kanban::kanban.bulk_limit', ['max' => Board::MAX_SELECTION])];
        }

        $board->findRecords($ids); // one query for every card; move() reads them from there

        foreach (Board::selectionIds($ids) as $id) {
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
            'icons' => $board->getIcons(),
        ];
    }

    /**
     * Hook after a successful move (log it, notify someone…).
     *
     * @param  array<string, mixed>  $card
     */
    protected function kanbanMoved(string $id, string $to, array $card): void {}

    /**
     * What the view hands to the browser, once per request. The board's state (cards,
     * counts, totals, summaries) is loaded the first time the board is drawn, on
     * whichever request that is (a lazy or deferred board included): the board is
     * `wire:ignore`d, so a later re-render (an action's modal, a form submit) would
     * throw it away; those renders get `columns: null`. A board removed and drawn
     * again (toggled off and on) loads its state with one refresh.
     *
     * @return array<string, mixed>
     */
    public function getKanbanConfig(): array
    {
        if ($this->kanbanConfig === null) {
            $this->kanbanConfig = $this->buildKanbanConfig();
            $this->kanbanDrawn = true;
        }

        return $this->kanbanConfig;
    }

    /** @return array<string, mixed> */
    protected function buildKanbanConfig(): array
    {
        $board = $this->getKanban();

        // Card and create actions need Filament's action system on the component.
        $actions = $this instanceof HasActions;

        return [
            'key' => 'kanban:'.($board->getKey() ?? static::class).':'.(auth()->id() ?? 'guest'),
            'columns' => $this->kanbanDrawn ? null : $board->getState(),
            'icons' => $board->getIcons(),
            'lanes' => $board->hasLanes() ? $this->kanbanLanes($board) : null,
            'perColumn' => $board->getPerColumn(),
            'reorderable' => $board->isReorderable(),
            'searchable' => $board->isSearchable(),
            'filters' => array_map(fn ($f) => ['name' => $f->getName(), 'label' => $f->getLabel(), 'options' => collect($f->getOptions())->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()->all()], $board->getFilters()),
            'selectable' => $board->isSelectable(),
            'maxSelection' => Board::MAX_SELECTION,
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

<?php

namespace Packstub\Kanban\Concerns;

use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Livewire;
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
        return $this->kanbanBoard ??= $this->kanbanDefaults($this->kanban(Board::make()->key(static::class)));
    }

    /** What a page fills in after kanban() (a resource page: the resource's query). */
    protected function kanbanDefaults(Board $board): Board
    {
        return $board;
    }

    /**
     * How many cards the user may see on this board, over its visible columns, in one
     * grouped query: for a navigation badge. Builds the component without mount(), so
     * a kanban() that reads state set in mount() or a public property counts without it.
     */
    public static function getBoardCount(): int
    {
        // Once per request: the navigation may ask more than once.
        return once(fn () => app(static::class)->getKanban()->getTotalCount());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, int|array<string, int>>  $loaded  cards already shown per column (per lane with swimlanes), reloaded as many
     * @return array{columns: list<array<string, mixed>>, icons: array<string, string>, lanes?: list<array<string, mixed>>}
     */
    #[Renderless]
    public function kanbanRefresh(string $search = '', array $filters = [], array $loaded = [], bool $whole = false): array
    {
        $board = $this->getKanban();
        $columns = $board->getState($search, $filters, $loaded);

        return [
            // A board drawn again takes its columns whole, their header actions included.
            'columns' => $whole ? $this->kanbanColumnsWithActions($columns) : $columns,
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
     * @param  string|null  $lane  with swimlanes, the lane the card was dropped in
     * @param  string|null  $origin  the tab's token, echoed in the broadcast so that tab ignores it
     * @return array{ok: bool, card?: array<string, mixed>, summaries?: array<string, string|null>, icons?: array<string, string>, message?: string}
     */
    #[Renderless]
    public function kanbanMove(string $id, string $to, ?array $order = null, string $search = '', array $filters = [], ?string $lane = null, ?string $origin = null): array
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
        // A drop back where it was on a board that does not reorder saved nothing: no one to tell.
        if ((string) $from !== $to || $lane !== null || ($order !== null && $board->isReorderable())) {
            $board->broadcastChange($id, (string) $from, $to, $origin);
        }

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
    public function kanbanMoveMany(array $ids, string $to, string $search = '', array $filters = [], ?string $lane = null, ?string $origin = null): array
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

        if ($moved !== []) {
            $board->broadcastChange(origin: $origin);
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
     * The search and filters in the page's query string, checked as any value from the
     * browser is: only the board's own filters, only values among their options.
     *
     * @return array{search: string, filters: array<string, string|list<string>|bool>}
     */
    protected function kanbanUrlState(Board $board): array
    {
        $search = request()->query('search');
        $sent = request()->query('filters');
        $filters = [];

        foreach ($board->getFilters() as $filter) {
            if (is_array($sent) && array_key_exists($filter->getName(), $sent)) {
                $filters[$filter->getName()] = $filter->state($sent[$filter->getName()]);
            }
        }

        return [
            'search' => $board->isSearchable() && is_string($search) ? trim($search) : '',
            'filters' => $filters,
        ];
    }

    /**
     * What the browser needs to draw an action in a menu.
     *
     * @return array{name: string, label: mixed, icon: string|null, color: string|null}
     */
    protected function kanbanActionSummary(Action $action): array
    {
        return [
            'name' => $action->getName(),
            'label' => $action->getLabel(),
            'icon' => ($icon = $action->getIcon() ?? $action->getGroupedIcon()) ? \Filament\Support\generate_icon_html($icon)?->toHtml() : null,
            'color' => is_string($color = $action->getColor()) ? $color : null,
        ];
    }

    /**
     * The columns' state with each one's header actions (on components with Filament's
     * action system), for a board drawn whole.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return list<array<string, mixed>>
     */
    protected function kanbanColumnsWithActions(array $columns): array
    {
        $actions = $this instanceof HasActions;

        return array_map(fn (array $column) => [
            ...$column,
            'actions' => $actions ? $this->kanbanColumnActionSummaries($column['name']) : [],
        ], $columns);
    }

    /**
     * A column's actions as the menu shows them: the ones the app hides (hidden(),
     * visible(), authorize()) are left out, evaluated with the column as argument.
     *
     * @return list<array{name: string, label: mixed, icon: string|null, color: string|null}>
     */
    protected function kanbanColumnActionSummaries(string $columnName): array
    {
        $summaries = [];

        foreach ($this->getKanban()->getColumn($columnName)?->getActions() ?? [] as $action) {
            $previous = $action->hasArguments() ? $action->getArguments() : null;
            $action->arguments(['kanbanColumn' => $columnName]);

            try {
                if (! $action->isHidden()) {
                    $summaries[] = $this->kanbanActionSummary($action);
                }
            } finally {
                $action->arguments($previous);
            }
        }

        return $summaries;
    }

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

        // A toggle without query() fails here, on the first render, not when a user clicks it.
        foreach ($board->getFilters() as $filter) {
            $filter->assertUsable();
        }

        // The page itself (not a Livewire update) is drawn as its URL says: a bookmarked or
        // shared board shows filtered from the start, with no second request.
        $initial = $board->persistsInUrl() && ! Livewire::isLivewireRequest() ? $this->kanbanUrlState($board) : null;

        return [
            'key' => 'kanban:'.($board->getKey() ?? static::class).':'.(auth()->id() ?? 'guest'),
            'columns' => $this->kanbanDrawn ? null : $this->kanbanColumnsWithActions($board->getState($initial['search'] ?? '', $initial['filters'] ?? [])),
            'initial' => $initial,
            'icons' => $board->getIcons(),
            'lanes' => $board->hasLanes() ? $this->kanbanLanes($board) : null,
            'perColumn' => $board->getPerColumn(),
            'reorderable' => $board->isReorderable(),
            'searchable' => $board->isSearchable(),
            'filters' => array_map(fn ($f) => ['name' => $f->getName(), 'label' => $f->getLabel(), 'type' => $f->getType(), 'options' => collect($f->getOptions())->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()->all()], $board->getFilters()),
            'selectable' => $board->isSelectable(),
            'maxSelection' => Board::MAX_SELECTION,
            'focus' => $board->hasFocusMode(),
            'url' => $board->persistsInUrl(),
            'poll' => $board->getPoll(),
            'density' => $board->getDensity(),
            'undo' => $board->getUndo(),
            'broadcast' => $board->isBroadcasting() ? ['channel' => $board->getBroadcastChannel(), 'event' => $board->getBroadcastEvent(), 'board' => $board->getKey()] : null,
            'cardActions' => array_map($this->kanbanActionSummary(...), $actions ? $board->getCardActions() : []),
            'cardAction' => $board->getCardAction(),
            'bulkActions' => array_map($this->kanbanActionSummary(...), $actions ? $board->getBulkActions() : []),
            'createAction' => $actions && ($create = $board->getCreateAction()) ? ['name' => $create->getName(), 'label' => $create->getLabel()] : null,
            'i18n' => __('packstub-kanban::kanban'),
            'locale' => app()->getLocale(),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function kanbanLanes(Board $board): array
    {
        return array_map(fn ($lane) => $lane->toArray(), $board->getLanes() ?? []);
    }
}

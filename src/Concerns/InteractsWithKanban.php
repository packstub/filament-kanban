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
     * @param  array<string, int>  $loaded  cards already shown per column, reloaded as many
     * @return array{columns: list<array<string, mixed>>}
     */
    #[Renderless]
    public function kanbanRefresh(string $search = '', array $filters = [], array $loaded = []): array
    {
        return ['columns' => $this->getKanban()->getState($search, $filters, $loaded)];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    #[Renderless]
    public function kanbanMore(string $column, int $offset, string $search = '', array $filters = []): array
    {
        return $this->getKanban()->getCards($column, $search, $filters, $offset);
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
    public function kanbanMove(string $id, string $to, ?array $order = null, string $search = '', array $filters = []): array
    {
        $board = $this->getKanban();
        $from = $board->findRecord($id)?->getAttribute($board->getColumnAttribute());
        $from = $from instanceof \BackedEnum ? $from->value : $from;

        try {
            $card = $board->move($id, $to, $order);
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
            'createAction' => $actions && ($create = $board->getCreateAction()) ? ['name' => $create->getName(), 'label' => $create->getLabel()] : null,
            'i18n' => __('packstub-kanban::kanban'),
        ];
    }
}

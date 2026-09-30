<?php

namespace Packstub\Kanban\Concerns;

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
     * @return array{columns: list<array<string, mixed>>}
     */
    #[Renderless]
    public function kanbanRefresh(string $search = '', array $filters = []): array
    {
        return ['columns' => $this->getKanban()->getState($search, $filters)];
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

    /** @return array<string, mixed> */
    public function getKanbanConfig(): array
    {
        $board = $this->getKanban();

        return [
            'key' => 'kanban:'.($board->getKey() ?? static::class).':'.(auth()->id() ?? 'guest'),
            'columns' => $board->getState(),
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
            ], $board->getCardActions()),
            'cardAction' => $board->getCardAction(),
            'createAction' => ($create = $board->getCreateAction()) ? ['name' => $create->getName(), 'label' => $create->getLabel()] : null,
            'i18n' => __('packstub-kanban::kanban'),
        ];
    }
}

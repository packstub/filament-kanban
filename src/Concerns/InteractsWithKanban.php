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
     * @return array{ok: bool, card?: array<string, mixed>, message?: string}
     */
    #[Renderless]
    public function kanbanMove(string $id, string $to, ?array $order = null): array
    {
        try {
            $card = $this->getKanban()->move($id, $to, $order);
        } catch (MoveRejected $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $this->kanbanMoved($id, $to, $card);

        return ['ok' => true, 'card' => $card];
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
            'i18n' => __('packstub-kanban::kanban'),
        ];
    }
}

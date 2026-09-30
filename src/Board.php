<?php

namespace Packstub\Kanban;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Conditionable;
use Packstub\Kanban\Exceptions\MoveRejected;

/**
 * The board definition: which records, which columns, what a card shows and what a
 * move does. The page renders it once as JSON; after that the browser owns the
 * board and only asks the server to move a card, load more or re-run a search.
 */
class Board
{
    use Conditionable;

    protected Builder|Closure|null $query = null;

    protected string $columnAttribute = 'status';

    /** @var list<Column>|Closure */
    protected array|Closure $columns = [];

    protected ?Closure $card = null;

    protected ?string $orderAttribute = null;

    /** @var array{0: string, 1: string}|null */
    protected ?array $sort = null;

    /** @var list<string> */
    protected array $searchable = [];

    /** @var list<Filter> */
    protected array $filters = [];

    protected int $perColumn = 50;

    protected ?Closure $moveUsing = null;

    protected bool $focusMode = true;

    protected ?string $key = null;

    /** @var list<Column>|null */
    protected ?array $resolvedColumns = null;

    public static function make(): static
    {
        return new static;
    }

    /* ------------------------------------------------------------------ configuration */

    /** The records on the board. Scope it here (tenant, date window…): every read and every move goes through it. */
    public function query(Builder|Closure $query): static
    {
        $this->query = $query;

        return $this;
    }

    /** The attribute whose value is the column name. Enums are read through their backing value. */
    public function columnAttribute(string $attribute): static
    {
        $this->columnAttribute = $attribute;

        return $this;
    }

    /** @param  list<Column>|Closure  $columns */
    public function columns(array|Closure $columns): static
    {
        $this->columns = $columns;
        $this->resolvedColumns = null;

        return $this;
    }

    /** @param  Closure(Model): Card  $callback */
    public function card(Closure $callback): static
    {
        $this->card = $callback;

        return $this;
    }

    /** Default order of the cards in every column (a column can override it). */
    public function sortBy(string $column, string $direction = 'asc'): static
    {
        $this->sort = [$column, strtolower($direction) === 'desc' ? 'desc' : 'asc'];

        return $this;
    }

    /** Let users reorder cards inside a column; the order is stored as integers in this attribute. */
    public function reorderable(string $orderAttribute = 'sort'): static
    {
        $this->orderAttribute = $orderAttribute;

        return $this;
    }

    /** @param  list<string>  $attributes  dotted names search a relationship (`customer.name`) */
    public function searchable(array $attributes): static
    {
        $this->searchable = $attributes;

        return $this;
    }

    /** @param  list<Filter>  $filters */
    public function filters(array $filters): static
    {
        $this->filters = $filters;

        return $this;
    }

    public function perColumn(int $cards): static
    {
        $this->perColumn = max(1, $cards);

        return $this;
    }

    /**
     * What a move does. Throw to refuse it: the card snaps back and the exception's
     * message is shown. Default: set the column attribute and save.
     *
     * @param  Closure(Model $record, string $to, string $from): void  $callback
     */
    public function moveUsing(Closure $callback): static
    {
        $this->moveUsing = $callback;

        return $this;
    }

    /** Hide the panel's sidebar on the board page so the columns get the whole width (a toggle brings it back). */
    public function focusMode(bool $condition = true): static
    {
        $this->focusMode = $condition;

        return $this;
    }

    /** Where the browser remembers folded and hidden columns; defaults to the page's class. */
    public function key(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /* ------------------------------------------------------------------ reading */

    /** @return list<Column> the columns this user may see, in order */
    public function getColumns(): array
    {
        return $this->resolvedColumns ??= array_values(array_filter(
            $this->columns instanceof Closure ? app()->call($this->columns) : $this->columns,
            fn (Column $column) => $column->isVisible(),
        ));
    }

    public function getColumn(string $name): ?Column
    {
        foreach ($this->getColumns() as $column) {
            if ($column->getName() === $name) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<Filter> */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function isSearchable(): bool
    {
        return $this->searchable !== [];
    }

    public function isReorderable(): bool
    {
        return $this->orderAttribute !== null;
    }

    public function hasFocusMode(): bool
    {
        return $this->focusMode;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function getPerColumn(): int
    {
        return $this->perColumn;
    }

    /**
     * Everything the browser needs to draw the board.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getState(string $search = '', array $filters = []): array
    {
        $columns = $this->getColumns();
        $counts = $this->counts($search, $filters);

        return array_map(fn (Column $column) => [
            'name' => $column->getName(),
            'label' => $column->getLabel(),
            'color' => $column->getColor(),
            'collapsed' => $column->isCollapsed(),
            'droppable' => $column->isDroppable(),
            'draggable' => $column->isDraggable(),
            'accepts' => $column->getAccepts(),
            'count' => $counts[$column->getName()] ?? 0,
            'cards' => $this->getCards($column->getName(), $search, $filters),
        ], $columns);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getCards(string $columnName, string $search = '', array $filters = [], int $offset = 0): array
    {
        $column = $this->getColumn($columnName);

        if (! $column) {
            return [];
        }

        $query = $this->filteredQuery($search, $filters)
            ->where($this->qualifiedColumnAttribute(), $columnName);

        foreach ($this->orderingFor($column) as [$attribute, $direction]) {
            $query->orderBy($attribute, $direction);
        }

        return $query
            ->orderBy($query->getModel()->getQualifiedKeyName())
            ->offset(max(0, $offset))
            ->limit($this->perColumn)
            ->get()
            ->map(fn (Model $record) => $this->presentCard($record))
            ->all();
    }

    /** @return array<string, mixed> */
    public function presentCard(Model $record): array
    {
        $card = $this->card ? ($this->card)($record) : Card::make()->title((string) $record->getKey());

        return ['id' => (string) $record->getKey(), ...$card->toArray()];
    }

    /* ------------------------------------------------------------------ writing */

    /**
     * Move a card, enforcing the column rules on the server whatever the browser
     * claimed. Returns the card as it looks after the move.
     *
     * @param  list<string>|null  $order  the target column's card ids, top to bottom, when reorderable
     * @return array<string, mixed>
     *
     * @throws MoveRejected
     */
    public function move(string $id, string $to, ?array $order = null): array
    {
        $record = $this->baseQuery()->whereKey($id)->first()
            ?? throw new MoveRejected(__('packstub-kanban::kanban.missing'));

        $from = $this->columnValue($record);
        $source = $this->getColumn($from);
        $target = $this->getColumn($to);

        if (! $source || ! $target) {
            throw new MoveRejected(__('packstub-kanban::kanban.not_allowed'));
        }

        if ($from !== $to) {
            if (! $source->isDraggable() || ! $target->isDroppable() || ! $target->accepting($from)) {
                throw new MoveRejected(__('packstub-kanban::kanban.not_allowed_into', ['column' => $target->getLabel()]));
            }

            try {
                if ($this->moveUsing) {
                    ($this->moveUsing)($record, $to, $from);
                } else {
                    $record->setAttribute($this->columnAttribute, $to)->save();
                }
            } catch (MoveRejected $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new MoveRejected($e->getMessage() ?: __('packstub-kanban::kanban.failed'), previous: $e);
            }
        } elseif (! $this->isReorderable() || ! $source->isDraggable()) {
            return $this->presentCard($record);
        }

        if ($this->isReorderable() && $order !== null) {
            $this->storeOrder($to, $order);
        }

        return $this->presentCard($record->fresh() ?? $record);
    }

    /* ------------------------------------------------------------------ internals */

    public function baseQuery(): Builder
    {
        $query = $this->query instanceof Closure ? app()->call($this->query) : $this->query;

        if (! $query instanceof Builder) {
            throw new \LogicException('Kanban board needs a query(): an Eloquent builder or a closure returning one.');
        }

        return (clone $query)->whereIn(
            $this->qualifiedColumnAttribute($query),
            array_map(fn (Column $c) => $c->getName(), $this->getColumns()),
        );
    }

    /** @param  array<string, mixed>  $filters */
    public function filteredQuery(string $search = '', array $filters = []): Builder
    {
        $query = $this->baseQuery();

        foreach ($this->filters as $filter) {
            $filter->apply($query, $filters[$filter->getName()] ?? null);
        }

        $search = trim($search);

        if ($search !== '' && $this->searchable !== []) {
            // `!` escapes the wildcards: the same ESCAPE clause works on SQLite, MySQL and Postgres.
            $needle = '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search)).'%';

            $query->where(function (Builder $q) use ($needle) {
                foreach ($this->searchable as $attribute) {
                    if (str_contains($attribute, '.')) {
                        $relation = str($attribute)->beforeLast('.')->toString();
                        $column = str($attribute)->afterLast('.')->toString();
                        $q->orWhereHas($relation, fn (Builder $r) => $r->whereRaw('lower('.$r->qualifyColumn($column).") like ? escape '!'", [$needle]));
                    } else {
                        $q->orWhereRaw('lower('.$q->qualifyColumn($attribute).") like ? escape '!'", [$needle]);
                    }
                }
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    protected function counts(string $search, array $filters): array
    {
        $query = $this->filteredQuery($search, $filters);
        $attribute = $this->qualifiedColumnAttribute($query);

        return $query->reorder()
            ->toBase()
            ->select($attribute.' as kanban_column', DB::raw('count(*) as kanban_count'))
            ->groupBy($attribute)
            ->pluck('kanban_count', 'kanban_column')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** @return list<array{0: string, 1: string}> */
    protected function orderingFor(Column $column): array
    {
        if ($this->orderAttribute) {
            return [[$this->orderAttribute, 'asc'], ...array_filter([$column->getSort() ?? $this->sort])];
        }

        return array_values(array_filter([$column->getSort() ?? $this->sort]));
    }

    /** @param  list<string>  $order */
    protected function storeOrder(string $column, array $order): void
    {
        $query = $this->baseQuery();

        $ids = $query
            ->where($this->qualifiedColumnAttribute($query), $column)
            ->whereKey($order)
            ->pluck($query->getModel()->getQualifiedKeyName())
            ->map(fn ($id) => (string) $id)
            ->all();

        $position = 0;

        foreach ($order as $id) {
            if (in_array((string) $id, $ids, true)) {
                $this->baseQuery()->whereKey($id)->toBase()->update([$this->orderAttribute => ++$position]);
            }
        }
    }

    protected function columnValue(Model $record): string
    {
        $value = $record->getAttribute($this->columnAttribute);

        return (string) ($value instanceof \BackedEnum ? $value->value : $value);
    }

    protected function qualifiedColumnAttribute(Builder|Relation|null $query = null): string
    {
        return ($query ?? $this->baseQueryWithoutColumns())->qualifyColumn($this->columnAttribute);
    }

    protected function baseQueryWithoutColumns(): Builder
    {
        return $this->query instanceof Closure ? app()->call($this->query) : $this->query;
    }
}

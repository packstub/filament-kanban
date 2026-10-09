<?php

namespace Packstub\Kanban;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Conditionable;
use Packstub\Kanban\Events\CardMoved;
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

    /** @var list<Action> */
    protected array $cardActions = [];

    protected ?string $cardAction = null;

    protected ?Action $createAction = null;

    protected ?Closure $summarize = null;

    protected ?int $poll = null;

    /** @var list<Column>|null */
    protected ?array $resolvedColumns = null;

    /** @var array<string, Model|null> */
    protected array $records = [];

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

    /**
     * The columns, in order. Pass a backed enum's class to get one column per case
     * (see Column::fromEnum() to adjust them).
     *
     * @param  list<Column>|Closure|class-string<BackedEnum>  $columns
     */
    public function columns(array|Closure|string $columns): static
    {
        $this->columns = is_string($columns) ? Column::fromEnum($columns) : $columns;
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
     * What a move does. Throw MoveRejected to refuse it with a message the user
     * sees; any other exception is reported and the user sees "The move did not go
     * through." Default: set the column attribute and save.
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

    /**
     * Filament actions offered in each card's menu (edit, view, delete, your own…).
     * The card's record is the action's record, resolved through the board's query.
     *
     * @param  list<Action>  $actions
     */
    public function cardActions(array $actions): static
    {
        $this->cardActions = array_values($actions);

        return $this;
    }

    /** The card action a click on the card runs (a modal or a slide-over), instead of following its url. */
    public function cardAction(?string $name): static
    {
        $this->cardAction = $name;

        return $this;
    }

    /**
     * A create action (Filament's CreateAction, with your form) behind a "+" on each
     * column. The new record gets that column: the column attribute is set in the data.
     */
    public function createAction(?Action $action): static
    {
        $this->createAction = $action;

        return $this;
    }

    /**
     * A line under each column's title: a sum, an average… The query is the column's
     * cards with the current search and filters; return a string (or null for nothing).
     *
     * @param  Closure(Builder $query, Column $column): (string|int|float|null)  $callback
     */
    public function summarize(?Closure $callback): static
    {
        $this->summarize = $callback;

        return $this;
    }

    /** Refresh the board from the server every so often (`'10s'`, `'1m'` or milliseconds), while nobody is dragging. */
    public function poll(string|int|null $interval = '10s'): static
    {
        if ($interval === null) {
            $this->poll = null;
        } elseif (is_int($interval)) {
            $this->poll = max(1000, $interval);
        } else {
            preg_match('/^(\d+)\s*(ms|s|m)?$/', trim($interval), $m)
                ?: throw new \InvalidArgumentException("Cannot read the poll interval [{$interval}]: use '10s', '1m' or milliseconds.");
            $this->poll = max(1000, (int) $m[1] * match ($m[2] ?? 'ms') {
                's' => 1000, 'm' => 60000, default => 1
            });
        }

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

    public function getColumnAttribute(): string
    {
        return $this->columnAttribute;
    }

    /** @return list<Action> */
    public function getCardActions(): array
    {
        return $this->cardActions;
    }

    public function getCardAction(): ?string
    {
        return $this->cardAction;
    }

    public function getCreateAction(): ?Action
    {
        return $this->createAction;
    }

    public function getPoll(): ?int
    {
        return $this->poll;
    }

    /** @return class-string<Model> */
    public function getModel(): string
    {
        return $this->baseQueryWithoutColumns()->getModel()::class;
    }

    /** The record behind a card, if it is still on this board for this user. */
    public function findRecord(string|int|null $id): ?Model
    {
        if (blank($id)) {
            return null;
        }

        return array_key_exists($key = (string) $id, $this->records)
            ? $this->records[$key]
            : $this->records[$key] = $this->baseQuery()->whereKey($key)->first();
    }

    /** Whether a new card may be created in this column now: visible, droppable, creatable and not full. */
    public function canCreateIn(?string $columnName): bool
    {
        $column = $columnName === null ? null : $this->getColumn($columnName);

        return $column !== null
            && $this->createAction !== null
            && $column->isDroppable()
            && $column->isCreatable()
            && ! $this->isFull($column);
    }

    public function isFull(Column $column): bool
    {
        $limit = $column->getLimit();

        return $limit !== null && $this->columnQuery($column->getName())->count() >= $limit;
    }

    /** @param  array<string, mixed>  $filters */
    public function getSummary(string $columnName, string $search = '', array $filters = []): ?string
    {
        $column = $this->getColumn($columnName);

        if (! $this->summarize || ! $column) {
            return null;
        }

        $summary = app()->call($this->summarize, [
            'query' => $this->filteredQuery($search, $filters)->where($this->qualifiedColumnAttribute(), $columnName),
            'column' => $column,
        ]);

        return blank($summary) ? null : (string) $summary;
    }

    /**
     * @param  list<string>  $columnNames
     * @param  array<string, mixed>  $filters
     * @return array<string, string|null>
     */
    public function getSummaries(array $columnNames, string $search = '', array $filters = []): array
    {
        if (! $this->summarize) {
            return [];
        }

        return collect($columnNames)
            ->unique()
            ->filter(fn (string $name) => $this->getColumn($name) !== null)
            ->mapWithKeys(fn (string $name) => [$name => $this->getSummary($name, $search, $filters)])
            ->all();
    }

    /**
     * Everything the browser needs to draw the board.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, int>  $loaded  cards the browser already shows per column, kept on a refresh
     * @return list<array<string, mixed>>
     */
    public function getState(string $search = '', array $filters = [], array $loaded = []): array
    {
        $columns = $this->getColumns();
        $counts = $this->countsOf($this->filteredQuery($search, $filters));

        // What the WIP limits count, unfiltered: one grouped query, only when a column has a limit.
        $totals = array_filter($columns, fn (Column $column) => $column->getLimit() !== null) !== []
            ? $this->countsOf($this->baseQuery())
            : [];

        return array_map(fn (Column $column) => [
            'name' => $column->getName(),
            'label' => $column->getLabel(),
            'color' => $column->getColor(),
            'collapsed' => $column->isCollapsed(),
            'droppable' => $column->isDroppable(),
            'draggable' => $column->isDraggable(),
            'accepts' => $column->getAccepts(),
            'limit' => $column->getLimit(),
            'total' => $column->getLimit() === null ? null : ($totals[$column->getName()] ?? 0),
            'creatable' => $this->createAction !== null && $column->isDroppable() && $column->isCreatable(),
            'summary' => $this->getSummary($column->getName(), $search, $filters),
            'count' => $counts[$column->getName()] ?? 0,
            'cards' => $this->getCards($column->getName(), $search, $filters, limit: (int) ($loaded[$column->getName()] ?? 0)),
        ], $columns);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  int  $limit  more than perColumn() to reload pages already shown (at most 10 pages)
     * @return list<array<string, mixed>>
     */
    public function getCards(string $columnName, string $search = '', array $filters = [], int $offset = 0, int $limit = 0): array
    {
        $column = $this->getColumn($columnName);

        if (! $column) {
            return [];
        }

        $query = $this->filteredQuery($search, $filters)
            ->where($this->qualifiedColumnAttribute(), $columnName);

        $this->applyOrdering($query, $column);

        return $query
            ->offset(max(0, $offset))
            ->limit(min(max($this->perColumn, $limit), $this->perColumn * 10))
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
     * claimed. Returns the card as it looks after the move. A refused move throws
     * MoveRejected; an exception thrown while saving (the database's, moveUsing()'s)
     * propagates as it is.
     *
     * @param  list<string>|null  $order  the target column's card ids, top to bottom, when reorderable
     * @return array<string, mixed>
     *
     * @throws MoveRejected
     */
    public function move(string $id, string $to, ?array $order = null): array
    {
        $record = $this->findRecord($id)
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

            if ($this->isFull($target)) {
                throw new MoveRejected(__('packstub-kanban::kanban.full', ['column' => $target->getLabel(), 'limit' => $target->getLimit()]));
            }
        } elseif (! $this->isReorderable() || ! $source->isDraggable()) {
            return $this->presentCard($record);
        }

        // The move and the column's new order land together or not at all.
        try {
            $record->getConnection()->transaction(function () use ($record, $from, $to, $order) {
                $this->saveMove($record, $from, $to, $order);
            });
        } finally {
            // Saved or rolled back, the cached model no longer tells where the card is.
            unset($this->records[(string) $id]);
        }

        return $this->presentCard($record->fresh() ?? $record);
    }

    /** @param  list<string>|null  $order */
    protected function saveMove(Model $record, string $from, string $to, ?array $order): void
    {
        if ($from !== $to) {
            // A MoveRejected thrown here refuses with its message (and rolls back);
            // anything else (a database error, a bug in the closure) propagates for
            // the caller to report: its message is not for the user (see
            // InteractsWithKanban::kanbanMove()).
            if ($this->moveUsing) {
                ($this->moveUsing)($record, $to, $from);
            } else {
                $record->setAttribute($this->columnAttribute, $to)->save();
            }

            // Once the (outermost) transaction commits. The move is saved by then: a
            // listener that throws is reported, it does not turn the move into a failure.
            $record->getConnection()->afterCommit(function () use ($record, $from, $to) {
                try {
                    CardMoved::dispatch($record, $from, $to, $this->key);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }

        if ($this->isReorderable() && $order !== null) {
            $this->storeOrder($to, $order);
        }
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

    /** One column's cards, unfiltered: what a WIP limit counts. */
    public function columnQuery(string $columnName): Builder
    {
        $query = $this->baseQuery();

        return $query->where($this->qualifiedColumnAttribute($query), $columnName);
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
     * Cards per column in one grouped query.
     *
     * @return array<string, int>
     */
    protected function countsOf(Builder $query): array
    {
        $attribute = $this->qualifiedColumnAttribute($query);

        return $query->reorder()
            ->toBase()
            ->select($attribute.' as kanban_column', DB::raw('count(*) as kanban_count'))
            ->groupBy($attribute)
            ->pluck('kanban_count', 'kanban_column')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** The order of a column's cards: position (a null one last), the column's sort, then the key. */
    protected function applyOrdering(Builder $query, Column $column): Builder
    {
        if ($this->orderAttribute) {
            $position = $query->getGrammar()->wrap($query->qualifyColumn($this->orderAttribute));
            $query->orderByRaw("case when {$position} is null then 1 else 0 end")->orderByRaw("{$position} asc");
        }

        if ($sort = $column->getSort() ?? $this->sort) {
            $query->orderBy(...$sort);
        }

        return $query->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /**
     * Renumber the column: the ids the browser sent (its loaded cards, top to bottom)
     * get 1..n, every other card of the column follows in its current order. Only
     * positions that change are written, and the rest of the column is never read
     * whole: the cards beyond the loaded page are left alone when they already sit
     * above n, shifted up together (one statement) when they collide, and the ones
     * without a position are numbered after them (a few hundred per statement).
     *
     * @param  list<string>  $order
     */
    protected function storeOrder(string $columnName, array $order): void
    {
        if (! $column = $this->getColumn($columnName)) {
            return;
        }

        $query = $this->columnQuery($columnName);
        $keyName = $query->getModel()->getQualifiedKeyName();
        $position = $query->qualifyColumn($this->orderAttribute);
        $update = $query->toBase()->reorder();
        $numeric = in_array($query->getModel()->getKeyType(), ['int', 'integer'], true);

        $current = $order === [] ? [] : (clone $update)
            ->whereIn($keyName, array_values(array_unique(array_map('strval', $order))))
            ->pluck($position, $keyName)
            ->mapWithKeys(fn ($value, $id) => [(string) $id => $value === null ? null : (int) $value])
            ->all();

        $sent = [];

        foreach ($order as $id) {
            if (array_key_exists($id = (string) $id, $current) && ! isset($sent[$id])) {
                $sent[$id] = count($sent) + 1;
            }
        }

        $this->writePositions($update, $keyName, $numeric, array_filter($sent, fn (int $value, string $id) => $current[$id] !== $value, ARRAY_FILTER_USE_BOTH));

        $tail = (clone $update)->whereNotIn($keyName, array_keys($sent));
        $range = (clone $tail)->whereNotNull($position)
            ->selectRaw('min('.$query->getGrammar()->wrap($position).') as kanban_low, max('.$query->getGrammar()->wrap($position).') as kanban_high')
            ->first();

        $shift = $range?->kanban_low === null ? 0 : max(0, count($sent) + 1 - (int) $range->kanban_low);

        if ($shift > 0) {
            (clone $tail)
                ->whereNotNull($position)
                ->update([$this->orderAttribute => DB::raw($query->getGrammar()->wrap($position)." + {$shift}")]);
        }

        $next = max(count($sent), $range?->kanban_high === null ? 0 : (int) $range->kanban_high + $shift);

        $unnumbered = [];

        foreach ($this->applyOrdering((clone $query)->whereNotIn($keyName, array_keys($sent))->whereNull($position), $column)->toBase()->pluck($keyName) as $id) {
            $unnumbered[(string) $id] = ++$next;
        }

        $this->writePositions($update, $keyName, $numeric, $unnumbered);
    }

    /**
     * Set positions by id, a CASE statement per few hundred cards.
     *
     * @param  array<string, int>  $positions
     */
    protected function writePositions(QueryBuilder $update, string $keyName, bool $numeric, array $positions): void
    {
        $connection = $update->getConnection();
        $key = $update->getGrammar()->wrap($keyName);

        foreach (array_chunk($positions, 500, preserve_keys: true) as $chunk) {
            $cases = implode(' ', array_map(
                fn (string $id, int $value) => 'when '.($numeric ? (int) $id : $connection->escape($id)).' then '.$value,
                array_keys($chunk),
                $chunk,
            ));

            (clone $update)
                ->whereIn($keyName, array_map('strval', array_keys($chunk)))
                ->update([$this->orderAttribute => DB::raw("case {$key} {$cases} end")]);
        }
    }

    protected function columnValue(Model $record): string
    {
        $value = $record->getAttribute($this->columnAttribute);

        return (string) ($value instanceof BackedEnum ? $value->value : $value);
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

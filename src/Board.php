<?php

namespace Packstub\Kanban;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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

    protected ?string $laneAttribute = null;

    /** @var list<Lane>|Closure|null null = derived from the data */
    protected array|Closure|null $lanes = null;

    /** @var list<Lane>|null */
    protected ?array $resolvedLanes = null;

    /** @var list<Action> */
    protected array $bulkActions = [];

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

    /**
     * Swimlanes: one row per value of this attribute, across every column. Pass the
     * lanes (Lane::make(...), a closure returning them, or a backed enum's class) or
     * nothing to derive them from the data: the distinct values on the board, in
     * first-seen order, with an "Unassigned" lane for null. Pass null to turn them off.
     *
     * @param  list<Lane>|Closure|class-string<BackedEnum>|null  $lanes
     */
    public function swimlanes(?string $attribute, array|Closure|string|null $lanes = null): static
    {
        $this->laneAttribute = $attribute;
        $this->lanes = is_string($lanes) ? Lane::fromEnum($lanes) : $lanes;
        $this->resolvedLanes = null;

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
     * With swimlanes, the lane the card was dropped in comes fourth (null when the
     * browser sent none): the default save sets both attributes.
     *
     * @param  Closure(Model $record, string $to, string $from, ?string $lane): void  $callback
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

    /**
     * Filament actions offered on the selection bar once cards are selected. A
     * Packstub\Kanban\Actions\BulkAction gets the selected records (`$records`),
     * loaded through the board's query, so a user never reaches a record not on
     * their board.
     *
     * @param  list<Action>  $actions
     */
    public function bulkActions(array $actions): static
    {
        $this->bulkActions = array_values($actions);

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

    public function hasLanes(): bool
    {
        return $this->laneAttribute !== null;
    }

    public function getLaneAttribute(): ?string
    {
        return $this->laneAttribute;
    }

    /**
     * The lanes, in order: the ones given, or the attribute's distinct values on the
     * board in first-seen order of the board's sort. Given lanes get an "Unassigned"
     * lane appended while the board holds cards outside them. Null without swimlanes.
     *
     * @return list<Lane>|null
     */
    public function getLanes(): ?array
    {
        if (! $this->hasLanes()) {
            return null;
        }

        if ($this->resolvedLanes !== null) {
            return $this->resolvedLanes;
        }

        $lanes = $this->lanes instanceof Closure ? app()->call($this->lanes) : $this->lanes;

        if ($lanes === null) {
            $lanes = array_map(fn ($value) => Lane::make($value), $this->laneValues());
        } else {
            $lanes = array_values($lanes);
            $values = array_map(fn (Lane $lane) => $lane->getValue(), $lanes);

            if (! in_array(Lane::UNASSIGNED, $values, true) && $this->laneQuery($this->baseQuery(), Lane::UNASSIGNED, $values)->exists()) {
                $lanes[] = Lane::make(Lane::UNASSIGNED);
            }
        }

        return $this->resolvedLanes = $lanes;
    }

    public function getLane(string $value): ?Lane
    {
        foreach ($this->getLanes() ?? [] as $lane) {
            if ($lane->getValue() === $value) {
                return $lane;
            }
        }

        return null;
    }

    /** @return list<Action> */
    public function getBulkActions(): array
    {
        return $this->bulkActions;
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

    /**
     * The records behind a selection, the ones still on this board for this user.
     *
     * @param  list<string|int>  $ids
     * @return EloquentCollection<int, Model>
     */
    public function findRecords(array $ids): EloquentCollection
    {
        $ids = array_values(array_unique(array_map('strval', array_filter($ids, fn ($id) => ! blank($id)))));

        return $ids === [] ? new EloquentCollection : $this->baseQuery()->whereKey($ids)->get();
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
        $counts = $this->counts($search, $filters);
        $lanes = $this->getLanes();

        return array_map(fn (Column $column) => [
            'name' => $column->getName(),
            'label' => $column->getLabel(),
            'color' => $column->getColor(),
            'collapsed' => $column->isCollapsed(),
            'droppable' => $column->isDroppable(),
            'draggable' => $column->isDraggable(),
            'accepts' => $column->getAccepts(),
            'limit' => $column->getLimit(),
            'total' => $column->getLimit() === null ? null : $this->columnQuery($column->getName())->count(),
            'creatable' => $this->createAction !== null && $column->isDroppable() && $column->isCreatable(),
            'summary' => $this->getSummary($column->getName(), $search, $filters),
            ...($lanes === null ? [
                'count' => $counts[$column->getName()] ?? 0,
                'cards' => $this->getCards($column->getName(), $search, $filters, limit: (int) ($loaded[$column->getName()] ?? 0)),
            ] : [
                'count' => array_sum($counts[$column->getName()] ?? []),
                'counts' => $counts[$column->getName()] ?? [],
                'cards' => $this->getLaneCards($column->getName(), $lanes, $counts[$column->getName()] ?? [], $search, $filters, (array) ($loaded[$column->getName()] ?? [])),
            ]),
        ], $columns);
    }

    /**
     * One query per lane that holds cards: a column's cards, lane by lane.
     *
     * @param  list<Lane>  $lanes
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $filters
     * @param  array<string, int>  $loaded
     * @return list<array<string, mixed>>
     */
    protected function getLaneCards(string $columnName, array $lanes, array $counts, string $search, array $filters, array $loaded): array
    {
        $cards = [];

        foreach ($lanes as $lane) {
            if (($counts[$lane->getValue()] ?? 0) > 0) {
                array_push($cards, ...$this->getCards($columnName, $search, $filters, limit: (int) ($loaded[$lane->getValue()] ?? 0), lane: $lane->getValue()));
            }
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  int  $limit  more than perColumn() to reload pages already shown (at most 10 pages)
     * @param  string|null  $lane  with swimlanes, the lane's cards only ('' for the unassigned lane)
     * @return list<array<string, mixed>>
     */
    public function getCards(string $columnName, string $search = '', array $filters = [], int $offset = 0, int $limit = 0, ?string $lane = null): array
    {
        $column = $this->getColumn($columnName);

        if (! $column) {
            return [];
        }

        $query = $this->filteredQuery($search, $filters)
            ->where($this->qualifiedColumnAttribute(), $columnName);

        if ($lane !== null && $this->hasLanes()) {
            $this->laneQuery($query, $lane);
        }

        foreach ($this->orderingFor($column) as [$attribute, $direction]) {
            $query->orderBy($attribute, $direction);
        }

        return $query
            ->orderBy($query->getModel()->getQualifiedKeyName())
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

        return [
            'id' => (string) $record->getKey(),
            ...($this->hasLanes() ? ['lane' => $this->laneValue($record)] : []),
            ...$card->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ writing */

    /**
     * Move a card, enforcing the column rules on the server whatever the browser
     * claimed. Returns the card as it looks after the move. A refused move throws
     * MoveRejected; an exception thrown while saving (the database's, moveUsing()'s)
     * propagates as it is.
     *
     * @param  list<string>|null  $order  the target column's card ids, top to bottom, when reorderable
     * @param  string|null  $lane  with swimlanes, the lane the card was dropped in ('' for unassigned); null leaves it
     * @return array<string, mixed>
     *
     * @throws MoveRejected
     */
    public function move(string $id, string $to, ?array $order = null, ?string $lane = null): array
    {
        $record = $this->findRecord($id)
            ?? throw new MoveRejected(__('packstub-kanban::kanban.missing'));

        $from = $this->columnValue($record);
        $source = $this->getColumn($from);
        $target = $this->getColumn($to);

        if (! $source || ! $target) {
            throw new MoveRejected(__('packstub-kanban::kanban.not_allowed'));
        }

        $lane = $this->hasLanes() ? $lane : null;
        $fromLane = $lane === null ? null : $this->laneValue($record);

        if ($lane !== null && $this->getLane($lane) === null) {
            throw new MoveRejected(__('packstub-kanban::kanban.not_allowed'));
        }

        if ($from !== $to || ($lane !== null && $lane !== $fromLane)) {
            if (! $source->isDraggable() || ! $target->isDroppable() || ($from !== $to && ! $target->accepting($from))) {
                throw new MoveRejected(__('packstub-kanban::kanban.not_allowed_into', ['column' => $target->getLabel()]));
            }

            if ($from !== $to && $this->isFull($target)) {
                throw new MoveRejected(__('packstub-kanban::kanban.full', ['column' => $target->getLabel(), 'limit' => $target->getLimit()]));
            }

            // A MoveRejected thrown here refuses with its message; anything else (a
            // database error, a bug in the closure) propagates for the caller to report:
            // its message is not for the user (see InteractsWithKanban::kanbanMove()).
            if ($this->moveUsing) {
                $this->hasLanes() ? ($this->moveUsing)($record, $to, $from, $lane) : ($this->moveUsing)($record, $to, $from);
            } else {
                $record->setAttribute($this->columnAttribute, $to);

                if ($lane !== null) {
                    $record->setAttribute($this->laneAttribute, $lane === Lane::UNASSIGNED ? null : $lane);
                }

                $record->save();
            }

            CardMoved::dispatch($record, $from, $to, $this->key, $lane);
        } elseif (! $this->isReorderable() || ! $source->isDraggable()) {
            return $this->presentCard($record);
        }

        if ($this->isReorderable() && $order !== null) {
            $this->storeOrder($to, $order);
        }

        unset($this->records[(string) $id]);

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
     * Cards per column; with swimlanes, per column and lane (one grouped query either way).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>|array<string, array<string, int>>
     */
    protected function counts(string $search, array $filters): array
    {
        $query = $this->filteredQuery($search, $filters);
        $attribute = $this->qualifiedColumnAttribute($query);

        if (! $this->hasLanes()) {
            return $query->reorder()
                ->toBase()
                ->select($attribute.' as kanban_column', DB::raw('count(*) as kanban_count'))
                ->groupBy($attribute)
                ->pluck('kanban_count', 'kanban_column')
                ->map(fn ($count) => (int) $count)
                ->all();
        }

        $laneAttribute = $query->qualifyColumn($this->laneAttribute);
        $known = array_map(fn (Lane $lane) => $lane->getValue(), $this->getLanes());
        $counts = [];

        foreach ($query->reorder()->toBase()->select($attribute.' as kanban_column', $laneAttribute.' as kanban_lane', DB::raw('count(*) as kanban_count'))->groupBy($attribute, $laneAttribute)->get() as $row) {
            $lane = (string) ($row->kanban_lane ?? Lane::UNASSIGNED);
            $lane = in_array($lane, $known, true) ? $lane : Lane::UNASSIGNED;
            $counts[(string) $row->kanban_column][$lane] = ($counts[(string) $row->kanban_column][$lane] ?? 0) + (int) $row->kanban_count;
        }

        return $counts;
    }

    /** The lane's cards: the unassigned lane holds null and any value outside the defined lanes. */
    protected function laneQuery(Builder $query, string $lane, ?array $known = null): Builder
    {
        $attribute = $query->qualifyColumn($this->laneAttribute);

        if ($lane !== Lane::UNASSIGNED) {
            return $query->where($attribute, $lane);
        }

        $known ??= array_map(fn (Lane $l) => $l->getValue(), $this->getLanes());
        $known = array_values(array_filter($known, fn (string $value) => $value !== Lane::UNASSIGNED));

        return $query->where(fn (Builder $q) => $q->whereNull($attribute)->when($known !== [], fn (Builder $q) => $q->orWhereNotIn($attribute, $known)));
    }

    /**
     * The attribute's distinct values on the board, in first-seen order of the board's sort.
     *
     * @return list<string>
     */
    protected function laneValues(): array
    {
        $query = $this->baseQuery();
        $attribute = $query->qualifyColumn($this->laneAttribute);
        [$sort, $direction] = $this->sort ?? [$query->getModel()->getKeyName(), 'asc'];
        $first = ($direction === 'desc' ? 'max' : 'min').'('.$query->qualifyColumn($sort).')';

        return $query->reorder()
            ->toBase()
            ->select($attribute.' as kanban_lane')
            ->selectRaw($first.' as kanban_first')
            ->groupBy($attribute)
            ->orderBy('kanban_first', $direction)
            ->pluck('kanban_lane')
            ->map(fn ($value) => (string) ($value ?? Lane::UNASSIGNED))
            ->unique()
            ->values()
            ->all();
    }

    protected function laneValue(Model $record): string
    {
        $value = $record->getAttribute($this->laneAttribute);
        $value = (string) ($value instanceof BackedEnum ? $value->value : ($value ?? Lane::UNASSIGNED));

        return $this->getLane($value) === null ? Lane::UNASSIGNED : $value;
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

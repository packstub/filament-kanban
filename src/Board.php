<?php

namespace Packstub\Kanban;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\MySqlConnection;
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

    /** The most ids one selection (a bulk move, a bulk action) may carry. */
    public const MAX_SELECTION = 500;

    /** The most lanes derived from the data; the rest fold into the "Other" lane. */
    public const MAX_DERIVED_LANES = 50;

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

    protected bool|Closure|null $selectable = null;

    /** @var array<string, bool> */
    protected array $recordSets = [];

    /** @var array<string, string|null>  badge icons the cards presented so far use, name => SVG */
    protected array $icons = [];

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
     * With swimlanes a lane change comes fourth: the new lane's value, '' (Lane::UNASSIGNED)
     * for a drop into the unassigned lane, null when the lane did not change. The default
     * save sets the column, and the lane (null for '') when it changed.
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
        foreach ($actions as $action) {
            if ($action instanceof BulkAction) {
                throw new \LogicException("The bulk action [{$action->getName()}] is a table action (Filament\\Actions\\BulkAction): on a board use Packstub\\Kanban\\Actions\\BulkAction, which gets the selected cards as \$records.");
            }
        }

        $this->bulkActions = array_values($actions);

        return $this;
    }

    /**
     * Let users select cards (Ctrl/⌘-click, Shift-click, the checkbox) and move
     * them together. On by default once the board has bulk actions; turn it on
     * without them for "Move to…" alone, or off to keep Ctrl/⌘-click opening the card.
     */
    public function selectable(bool|Closure $condition = true): static
    {
        $this->selectable = $condition;

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

    public function isSelectable(): bool
    {
        if ($this->selectable === null) {
            return $this->bulkActions !== [];
        }

        return (bool) ($this->selectable instanceof Closure ? app()->call($this->selectable) : $this->selectable);
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
            $lanes = $this->derivedLanes();
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
        $ids = static::selectionIds($ids);

        if ($ids === []) {
            return new EloquentCollection;
        }

        $records = $this->baseQuery()->whereKey($ids)->get();
        $byKey = $records->keyBy(fn (Model $record) => (string) $record->getKey());

        // findRecord() answers from these now: a bulk move loads its cards in one query.
        foreach ($ids as $id) {
            $this->records[$id] = $byKey->get($id);
        }

        return $records;
    }

    /** Whether any of these ids is still on the board (one exists() per set of ids, per request); false past the cap. */
    public function hasRecords(array $ids): bool
    {
        $ids = static::selectionIds($ids);

        if ($ids === []) {
            return false;
        }

        return $this->recordSets[implode(',', $ids)] ??= $this->baseQuery()->whereKey($ids)->exists();
    }

    /**
     * The ids a selection sent, cleaned: scalars only, as strings, unique. A selection
     * past MAX_SELECTION is refused as a whole (an empty list), never trimmed.
     *
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    public static function selectionIds(array $ids): array
    {
        return static::selectionTooLarge($ids) ? [] : static::cleanIds($ids);
    }

    /** @param  array<mixed>  $ids */
    public static function selectionTooLarge(array $ids): bool
    {
        return count(static::cleanIds($ids)) > static::MAX_SELECTION;
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    private static function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_map('strval', array_filter($ids, fn ($id) => is_scalar($id) && ! blank($id)))));
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
        $lanes = $this->getLanes();
        $counts = $lanes === null
            ? $this->countsOf($this->filteredQuery($search, $filters))
            : $this->laneCountsOf($this->filteredQuery($search, $filters));

        // What the WIP limits count, unfiltered: one grouped query, only when a column has a limit.
        $totals = array_filter($columns, fn (Column $column) => $column->getLimit() !== null) !== []
            ? $this->countsOf($this->baseQuery())
            : [];

        return array_map(fn (Column $column) => [
            'name' => $column->getName(),
            'label' => $column->getLabel(),
            'labelHtml' => $column->hasHtmlLabel(),
            'icon' => $column->getIcon(),
            'description' => $column->getDescription(),
            'color' => $column->getColor(),
            'collapsed' => $column->isCollapsed(),
            'droppable' => $column->isDroppable(),
            'draggable' => $column->isDraggable(),
            'accepts' => $column->getAccepts(),
            'limit' => $column->getLimit(),
            'total' => $column->getLimit() === null ? null : ($totals[$column->getName()] ?? 0),
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
     * A column's cards, lane by lane: one query for the whole column, limited per lane
     * with a window function (one query per lane on MySQL before 8, which has none).
     *
     * @param  list<Lane>  $lanes
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $filters
     * @param  array<string, int>  $loaded
     * @return list<array<string, mixed>>
     */
    protected function getLaneCards(string $columnName, array $lanes, array $counts, string $search, array $filters, array $loaded): array
    {
        $column = $this->getColumn($columnName);
        $wanted = [];

        foreach ($lanes as $lane) {
            if (($counts[$lane->getValue()] ?? 0) > 0) {
                $wanted[$lane->getValue()] = min(max($this->perColumn, (int) ($loaded[$lane->getValue()] ?? 0)), $this->perColumn * 10);
            }
        }

        if (! $column || $wanted === []) {
            return [];
        }

        $query = $this->filteredQuery($search, $filters)->where($this->qualifiedColumnAttribute(), $columnName);
        $connection = $query->getConnection();

        if (count($wanted) === 1 || ($connection instanceof MySqlConnection && ! $connection->isMaria() && version_compare($connection->getServerVersion(), '8.0.11', '<'))) {
            $cards = [];

            foreach ($wanted as $value => $limit) {
                array_push($cards, ...$this->getCards($columnName, $search, $filters, limit: $limit, lane: (string) $value));
            }

            return $cards;
        }

        $this->applyOrdering($query, $column);

        // The partition is the lane as laneCountsOf() sees it: a defined lane's value, or null
        // (the unassigned lane) for null and any other value.
        $attribute = $query->getGrammar()->wrap($query->qualifyColumn($this->laneAttribute));
        $known = array_values(array_filter(array_map(fn (Lane $lane) => $lane->getValue(), $lanes), fn (string $value) => $value !== Lane::UNASSIGNED));
        $partition = DB::raw("case when {$attribute} in (".implode(', ', array_map(fn (string $value) => $connection->escape($value), $known)).") then {$attribute} end");

        $byLane = [];

        foreach ($query->groupLimit(max($wanted), $partition)->get() as $record) {
            unset($record->laravel_row);
            $value = $this->laneValue($record);

            if (isset($wanted[$value]) && count($byLane[$value] ?? []) < $wanted[$value]) {
                $byLane[$value][] = $this->presentCard($record);
            }
        }

        $cards = [];

        foreach (array_keys($wanted) as $value) {
            array_push($cards, ...($byLane[(string) $value] ?? []));
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
        $card = [
            'id' => (string) $record->getKey(),
            ...($this->hasLanes() ? ['lane' => $this->laneValue($record)] : []),
            ...$card->toArray(),
        ];

        // A badge names its icon; the SVG goes to the browser once per request, not per card.
        foreach ($card['badges'] ?? [] as $badge) {
            if (isset($badge['icon']) && ! array_key_exists($badge['icon'], $this->icons)) {
                $this->icons[$badge['icon']] = \Filament\Support\generate_icon_html($badge['icon'])?->toHtml();
            }
        }

        return $card;
    }

    /**
     * The badge icons used by the cards presented in this request, as SVG by name, for
     * the browser's icon map. Every answer that carries cards carries these too.
     *
     * @return array<string, string>
     */
    public function getIcons(): array
    {
        return array_filter($this->icons);
    }

    /* ------------------------------------------------------------------ writing */

    /**
     * Move a card, enforcing the column rules on the server whatever the browser
     * claimed. Returns the card as it looks after the move. A refused move throws
     * MoveRejected; an exception thrown while saving (the database's, moveUsing()'s)
     * propagates as it is.
     *
     * @param  list<string>|null  $order  the target column's card ids, top to bottom, when reorderable
     * @param  string|null  $lane  with swimlanes, the lane the card was dropped in ('' for unassigned); null, or the card's own lane, leaves it
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

        if ($lane !== null && $this->getLane($lane) === null) {
            throw new MoveRejected(__('packstub-kanban::kanban.not_allowed'));
        }

        // The lane only changes when the card lands in another row: a move inside its own row
        // (or one that carried no lane) leaves the attribute alone, whatever value it holds.
        $lane = $lane !== null && $lane !== $this->laneValue($record) ? $lane : null;

        if ($lane !== null && ! $this->getLane($lane)->isDroppable()) {
            throw new MoveRejected(__('packstub-kanban::kanban.not_allowed'));
        }

        // The card's own rule (Card::locked()), re-read from the record: one card, so
        // the card closure runs once here, never over the column. A drop that changes
        // nothing (back into its own cell, no reordering) is answered as before.
        $unchanged = $from === $to && $lane === null && (! $this->isReorderable() || ! $source->isDraggable());

        if (! $unchanged && $this->card && ! ($this->card)($record)->isDraggable()) {
            throw new MoveRejected(__('packstub-kanban::kanban.locked'));
        }

        if ($from !== $to || $lane !== null) {
            if (! $source->isDraggable() || ! $target->isDroppable() || ($from !== $to && ! $target->accepting($from))) {
                throw new MoveRejected(__('packstub-kanban::kanban.not_allowed_into', ['column' => $target->getLabel()]));
            }

            if ($from !== $to && $this->isFull($target)) {
                throw new MoveRejected(__('packstub-kanban::kanban.full', ['column' => $target->getLabel(), 'limit' => $target->getLimit()]));
            }
        } elseif (! $this->isReorderable() || ! $source->isDraggable()) {
            return $this->presentCard($record);
        }

        // The move and the column's new order land together or not at all.
        try {
            $record->getConnection()->transaction(function () use ($record, $from, $to, $order, $lane) {
                $this->saveMove($record, $from, $to, $order, $lane);
            });
        } finally {
            // Saved or rolled back, the cached model no longer tells where the card is.
            unset($this->records[(string) $id]);
        }

        return $this->presentCard($record->fresh() ?? $record);
    }

    /** @param  list<string>|null  $order */
    protected function saveMove(Model $record, string $from, string $to, ?array $order, ?string $lane = null): void
    {
        if ($from !== $to || $lane !== null) {
            // A MoveRejected thrown here refuses with its message (and rolls back);
            // anything else (a database error, a bug in the closure) propagates for
            // the caller to report: its message is not for the user (see
            // InteractsWithKanban::kanbanMove()). moveUsing() hears about a lane only
            // when its closure takes a fourth parameter; otherwise the board saves the
            // lane itself and calls it for a column change only.
            $takesLane = $this->moveUsing && $this->hasLanes() && (new \ReflectionFunction($this->moveUsing))->getNumberOfParameters() >= 4;

            if ($this->moveUsing && ($takesLane || $from !== $to)) {
                if ($lane !== null && ! $takesLane) {
                    $record->setAttribute($this->laneAttribute, $lane === Lane::UNASSIGNED ? null : $lane);
                }

                $takesLane
                    ? ($this->moveUsing)($record, $to, $from, $lane)
                    : ($this->moveUsing)($record, $to, $from);

                if (! $takesLane && $record->isDirty($this->laneAttribute)) {
                    $record->save();
                }
            } else {
                $record->setAttribute($this->columnAttribute, $to);

                if ($lane !== null) {
                    $record->setAttribute($this->laneAttribute, $lane === Lane::UNASSIGNED ? null : $lane);
                }

                $record->save();
            }

            // Once the (outermost) transaction commits. The move is saved by then: a
            // listener that throws is reported, it does not turn the move into a failure.
            $record->getConnection()->afterCommit(function () use ($record, $from, $to, $lane) {
                try {
                    CardMoved::dispatch($record, $from, $to, $this->key, $lane);
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

    /**
     * With swimlanes: cards per column and lane, in one grouped query. A value outside
     * the lanes counts in the unassigned one.
     *
     * @return array<string, array<string, int>>
     */
    protected function laneCountsOf(Builder $query): array
    {
        $attribute = $this->qualifiedColumnAttribute($query);
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
     * One lane per value on the board, in first-seen order; a null value is the
     * "Unassigned" lane, which an empty board still shows so there is somewhere to
     * drop. Past MAX_DERIVED_LANES the rest fold into that lane, labelled "Other".
     * A backed enum cast on the attribute gives the labels and colours.
     *
     * @return list<Lane>
     */
    protected function derivedLanes(): array
    {
        $values = $this->laneValues();
        $capped = count($values) > static::MAX_DERIVED_LANES;
        $values = array_slice($values, 0, static::MAX_DERIVED_LANES);
        $enum = $this->laneEnum();

        $lanes = array_map(function (string $value) use ($enum) {
            $case = $enum && $value !== Lane::UNASSIGNED ? $enum::tryFrom(is_numeric($value) ? (int) $value : $value) : null;

            return $case ? Lane::fromCase($case) : Lane::make($value);
        }, $values);

        if ($capped || $values === []) {
            $lanes = array_values(array_filter($lanes, fn (Lane $lane) => ! $lane->isUnassigned()));
            // "Other" holds many values: a drop there could only erase the card's, so it takes none.
            $lanes[] = Lane::make(Lane::UNASSIGNED)->when($capped, fn (Lane $lane) => $lane->label(fn () => __('packstub-kanban::kanban.other'))->droppable(false));
        }

        return $lanes;
    }

    /** @return class-string<BackedEnum>|null the enum the lane attribute is cast to, if any */
    protected function laneEnum(): ?string
    {
        $cast = $this->baseQueryWithoutColumns()->getModel()->getCasts()[$this->laneAttribute] ?? null;

        return is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class) ? $cast : null;
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
        $first = ($direction === 'desc' ? 'max' : 'min').'('.$query->getGrammar()->wrap($query->qualifyColumn($sort)).')';

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

    /** The lane by the attribute's stored value, as the grouped counts and lane queries see it (not its cast). */
    protected function laneValue(Model $record): string
    {
        $value = $record->getAttributes()[$this->laneAttribute] ?? null;
        $value = (string) ($value instanceof BackedEnum ? $value->value : ($value ?? Lane::UNASSIGNED));

        return $this->getLane($value) === null ? Lane::UNASSIGNED : $value;
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

<?php

namespace Packstub\Kanban;

use BackedEnum;
use Closure;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Traits\Conditionable;

/**
 * One column of a board: the value of the board's column attribute, how it looks,
 * who sees it and which cards may be dropped into it. Every rule is evaluated once
 * per request (never per card), so a board with hundreds of cards stays cheap.
 */
class Column
{
    use Conditionable;

    protected string|Htmlable|Closure|null $label = null;

    protected string|Htmlable|null $resolvedLabel = null;

    protected bool $labelResolved = false;

    protected string|Closure|null $color = null;

    protected string|BackedEnum|Closure|null $icon = null;

    protected string|Closure|null $description = null;

    protected bool|Closure $visible = true;

    protected bool|Closure $droppable = true;

    protected bool|Closure $draggable = true;

    protected bool|Closure $collapsed = false;

    protected bool|Closure $creatable = true;

    protected int|Closure|null $limit = null;

    /** @var list<string>|Closure|null */
    protected array|Closure|null $accepts = null;

    protected ?string $sortColumn = null;

    protected string $sortDirection = 'asc';

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    /**
     * One column per case of a backed enum, in declaration order. Labels and colours
     * come from Filament's HasLabel and HasColor when the enum implements them.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return list<static>
     */
    public static function fromEnum(string $enum): array
    {
        return array_map(function (BackedEnum $case) {
            $column = static::make((string) $case->value);

            if ($case instanceof HasLabel) {
                $column->label(fn () => $case->getLabel());
            }

            if ($case instanceof HasColor) {
                $column->color(fn () => static::colorFromFilament($case->getColor()));
            }

            return $column;
        }, $enum::cases());
    }

    /** Plain text is escaped; an Htmlable (an HtmlString, a rendered view) is drawn as HTML. */
    public function label(string|Htmlable|Closure|null $label): static
    {
        $this->label = $label;
        $this->labelResolved = false;

        return $this;
    }

    /** A Heroicon (name or enum) next to the colour dot in the header. */
    public function icon(string|BackedEnum|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /** A tooltip on the column header: what belongs here, who moves cards out. */
    public function description(string|Closure|null $text): static
    {
        $this->description = $text;

        return $this;
    }

    /** A Tailwind-ish name (gray, sky, amber, orange, violet, emerald, red…) or any CSS colour. */
    public function color(string|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    /** Hide the column from this user: its cards are not loaded and nothing can be moved into or out of it. */
    public function visible(bool|Closure $condition = true): static
    {
        $this->visible = $condition;

        return $this;
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->visible = $condition instanceof Closure ? fn (...$args) => ! $condition(...$args) : ! $condition;

        return $this;
    }

    /** Whether this user may drop cards into the column. */
    public function droppable(bool|Closure $condition = true): static
    {
        $this->droppable = $condition;

        return $this;
    }

    /** Whether this user may drag cards out of the column. */
    public function draggable(bool|Closure $condition = true): static
    {
        $this->draggable = $condition;

        return $this;
    }

    /** Shown and dropped into, never changed from here: shorthand for draggable(false) + droppable(false). */
    public function readOnly(bool|Closure $condition = true): static
    {
        $this->draggable = $condition instanceof Closure ? fn (...$args) => ! $condition(...$args) : ! $condition;
        $this->droppable = $this->draggable;

        return $this;
    }

    /** Start folded to a thin strip; the user can unfold it and the board remembers. */
    public function collapsed(bool|Closure $condition = true): static
    {
        $this->collapsed = $condition;

        return $this;
    }

    /** Whether the board's create action offers a "+" on this column (it also needs to be droppable). */
    public function creatable(bool|Closure $condition = true): static
    {
        $this->creatable = $condition;

        return $this;
    }

    /**
     * A work-in-progress limit: once the column holds this many cards, nothing more is
     * moved or created into it. Counted on the board's query, whatever the search shows.
     */
    public function limit(int|Closure|null $cards): static
    {
        $this->limit = $cards;

        return $this;
    }

    /**
     * The columns a card may come from. Anything else is refused before the server
     * is asked, and the column is dimmed while such a card is being dragged.
     *
     * @param  list<string>|Closure|null  $columns  null = from anywhere
     */
    public function accepts(array|Closure|null $columns): static
    {
        $this->accepts = $columns;

        return $this;
    }

    /** Sort this column differently from the board (e.g. newest first in "Done"). */
    public function sortBy(string $column, string $direction = 'asc'): static
    {
        $this->sortColumn = $column;
        $this->sortDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        $label = $this->resolveLabel();

        if ($label instanceof Htmlable) {
            return $label->toHtml();
        }

        return (string) ($label ?? str($this->name)->headline());
    }

    /** Whether getLabel() is HTML (an Htmlable label) rather than text for the browser to escape. */
    public function hasHtmlLabel(): bool
    {
        return $this->resolveLabel() instanceof Htmlable;
    }

    /** A label closure runs once per column, whichever of getLabel() and hasHtmlLabel() asks first. */
    protected function resolveLabel(): string|Htmlable|null
    {
        if (! $this->labelResolved) {
            $this->resolvedLabel = $this->evaluate($this->label);
            $this->labelResolved = true;
        }

        return $this->resolvedLabel;
    }

    /** The icon rendered to SVG, for the browser. */
    public function getIcon(): ?string
    {
        $icon = $this->evaluate($this->icon);

        return $icon === null ? null : \Filament\Support\generate_icon_html($icon)?->toHtml();
    }

    public function getDescription(): ?string
    {
        $description = $this->evaluate($this->description);

        return blank($description) ? null : (string) $description;
    }

    public function getColor(): ?string
    {
        return $this->evaluate($this->color);
    }

    public function isVisible(): bool
    {
        return (bool) $this->evaluate($this->visible);
    }

    public function isDroppable(): bool
    {
        return (bool) $this->evaluate($this->droppable);
    }

    public function isDraggable(): bool
    {
        return (bool) $this->evaluate($this->draggable);
    }

    public function isCollapsed(): bool
    {
        return (bool) $this->evaluate($this->collapsed);
    }

    public function isCreatable(): bool
    {
        return (bool) $this->evaluate($this->creatable);
    }

    public function getLimit(): ?int
    {
        $limit = $this->evaluate($this->limit);

        return $limit === null ? null : max(0, (int) $limit);
    }

    /** @return list<string>|null */
    public function getAccepts(): ?array
    {
        $accepts = $this->evaluate($this->accepts);

        return $accepts === null ? null : array_values(array_map('strval', $accepts));
    }

    public function accepting(string $from): bool
    {
        $accepts = $this->getAccepts();

        return $accepts === null || in_array($from, $accepts, true);
    }

    /** @return array{0: string, 1: string}|null */
    public function getSort(): ?array
    {
        return $this->sortColumn ? [$this->sortColumn, $this->sortDirection] : null;
    }

    /** Filament colours are palettes (shade => value); the board draws one tone, the 500 shade. */
    public static function colorFromFilament(mixed $color): ?string
    {
        if (is_array($color)) {
            return $color[500] ?? (array_values($color)[0] ?? null);
        }

        return $color;
    }

    protected function evaluate(mixed $value): mixed
    {
        return $value instanceof Closure ? app()->call($value, ['column' => $this]) : $value;
    }
}

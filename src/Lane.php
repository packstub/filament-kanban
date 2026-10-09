<?php

namespace Packstub\Kanban;

use BackedEnum;
use Closure;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Traits\Conditionable;

/**
 * One swimlane of a board: a value of the board's lane attribute, drawn as a row
 * across every column. The empty value ('') is the "Unassigned" lane, where cards
 * whose lane is null (or not among the defined lanes) go.
 */
class Lane
{
    use Conditionable;

    public const UNASSIGNED = '';

    protected string|Closure|null $label = null;

    /** @var string|array<int|string, string>|Closure|null */
    protected string|array|Closure|null $color = null;

    protected bool|Closure $collapsed = false;

    protected bool $droppable = true;

    protected string $value;

    final public function __construct(string|int|null $value)
    {
        $this->value = (string) ($value ?? static::UNASSIGNED);
    }

    public static function make(string|int|null $value): static
    {
        return new static($value);
    }

    /**
     * One lane per case of a backed enum, in declaration order. Labels and colours
     * come from Filament's HasLabel and HasColor when the enum implements them.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return list<static>
     */
    public static function fromEnum(string $enum): array
    {
        return array_map(fn (BackedEnum $case) => static::fromCase($case), $enum::cases());
    }

    /** A lane for one enum case, with its label and colour when the enum implements HasLabel and HasColor. */
    public static function fromCase(BackedEnum $case): static
    {
        $lane = static::make($case->value);

        if ($case instanceof HasLabel) {
            $lane->label(fn () => $case->getLabel());
        }

        if ($case instanceof HasColor) {
            $lane->color(fn () => Column::colorFromFilament($case->getColor()));
        }

        return $lane;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** A Tailwind-ish name (gray, sky, amber…) or any CSS colour, drawn as a dot before the lane's label. */
    public function color(string|array|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    /** Start folded to its header; the user can unfold it and the board remembers. */
    public function collapsed(bool|Closure $condition = true): static
    {
        $this->collapsed = $condition;

        return $this;
    }

    /** Whether cards may be dropped into this lane from another one (the derived "Other" lane may not). */
    public function droppable(bool $condition = true): static
    {
        $this->droppable = $condition;

        return $this;
    }

    public function isDroppable(): bool
    {
        return $this->droppable;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function isUnassigned(): bool
    {
        return $this->value === static::UNASSIGNED;
    }

    public function getLabel(): string
    {
        $label = $this->evaluate($this->label);

        if ($label instanceof Htmlable) {
            return $label->toHtml();
        }

        if ($label !== null) {
            return (string) $label;
        }

        return $this->isUnassigned() ? __('packstub-kanban::kanban.unassigned') : str($this->value)->headline()->toString();
    }

    public function getColor(): ?string
    {
        return Column::colorFromFilament($this->evaluate($this->color));
    }

    public function isCollapsed(): bool
    {
        return (bool) $this->evaluate($this->collapsed);
    }

    /** @return array{value: string, label: string, color: ?string, collapsed: bool, droppable: bool} */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->getLabel(),
            'color' => $this->getColor(),
            'collapsed' => $this->isCollapsed(),
            'droppable' => $this->isDroppable(),
        ];
    }

    protected function evaluate(mixed $value): mixed
    {
        return $value instanceof Closure ? app()->call($value, ['lane' => $this]) : $value;
    }
}

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

    protected string|Closure|null $color = null;

    protected bool|Closure $collapsed = false;

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
        return array_map(function (BackedEnum $case) {
            $lane = static::make($case->value);

            if ($case instanceof HasLabel) {
                $lane->label(fn () => $case->getLabel());
            }

            if ($case instanceof HasColor) {
                $lane->color(fn () => Column::colorFromFilament($case->getColor()));
            }

            return $lane;
        }, $enum::cases());
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** A Tailwind-ish name (gray, sky, amber…) or any CSS colour, drawn as a dot before the lane's label. */
    public function color(string|Closure|null $color): static
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
        return $this->evaluate($this->color);
    }

    public function isCollapsed(): bool
    {
        return (bool) $this->evaluate($this->collapsed);
    }

    /** @return array{value: string, label: string, color: ?string, collapsed: bool} */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->getLabel(),
            'color' => $this->getColor(),
            'collapsed' => $this->isCollapsed(),
        ];
    }

    protected function evaluate(mixed $value): mixed
    {
        return $value instanceof Closure ? app()->call($value, ['lane' => $this]) : $value;
    }
}

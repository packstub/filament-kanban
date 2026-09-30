<?php

namespace Packstub\Kanban;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/** A single-choice filter shown as a small select in the board's toolbar. */
class Filter
{
    protected string|Closure|null $label = null;

    /** @var array<string|int, string>|Closure */
    protected array|Closure $options = [];

    protected ?Closure $query = null;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** @param  array<string|int, string>|Closure  $options */
    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    /** How a chosen value narrows the query. Default: `where(<name>, <value>)`. */
    public function query(Closure $callback): static
    {
        $this->query = $callback;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        $label = $this->label instanceof Closure ? app()->call($this->label) : $this->label;

        return (string) ($label ?? str($this->name)->beforeLast('_id')->headline());
    }

    /** @return array<string|int, string> */
    public function getOptions(): array
    {
        return $this->options instanceof Closure ? app()->call($this->options) : $this->options;
    }

    public function apply(Builder $query, mixed $value): void
    {
        if (blank($value) || ! array_key_exists($value, $this->getOptions())) {
            return;
        }

        if ($this->query) {
            ($this->query)($query, $value);

            return;
        }

        $query->where($query->qualifyColumn($this->name), $value);
    }
}

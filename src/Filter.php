<?php

namespace Packstub\Kanban;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * A filter in the board's toolbar: a small select by default, a multi-select with
 * multiple(), or an on/off chip with toggle(). Whatever the browser sends is checked
 * against the options again here.
 */
class Filter
{
    protected string|Closure|null $label = null;

    /** @var array<string|int, string>|Closure */
    protected array|Closure $options = [];

    /** The options, resolved once per request (a closure usually runs a query). */
    protected ?array $resolvedOptions = null;

    protected ?Closure $query = null;

    /** 'select', 'multiple' or 'toggle' */
    protected string $type = 'select';

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
        $this->resolvedOptions = null;

        return $this;
    }

    /**
     * How a chosen value narrows the query. Default: `where(<name>, <value>)`, or
     * `whereIn(<name>, <values>)` for a multiple() filter. The closure gets the value
     * (a string, a list of strings for multiple(), `true` for toggle()).
     */
    public function query(Closure $callback): static
    {
        $this->query = $callback;

        return $this;
    }

    /** Several options at once: query() gets a list, the default applies `whereIn`. */
    public function multiple(bool $condition = true): static
    {
        if ($condition) {
            $this->type = 'multiple';
        } elseif ($this->type === 'multiple') {
            $this->type = 'select';
        }

        return $this;
    }

    /** A single on/off chip with the filter's label, no options; query() gets `true` and is required. */
    public function toggle(bool $condition = true): static
    {
        if ($condition) {
            $this->type = 'toggle';
        } elseif ($this->type === 'toggle') {
            $this->type = 'select';
        }

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
        if ($this->type === 'toggle') {
            return [];
        }

        return $this->resolvedOptions ??= $this->options instanceof Closure ? app()->call($this->options) : $this->options;
    }

    public function hasQuery(): bool
    {
        return $this->query !== null;
    }

    /**
     * A toggle has no default query, so it needs one: checked when the board's config
     * is built (the first render), not the first time a user turns the chip on.
     *
     * @throws LogicException
     */
    public function assertUsable(): void
    {
        if ($this->type === 'toggle' && ! $this->query) {
            throw new LogicException("Kanban filter [{$this->name}] is a toggle: give it a query() closure, there is no default.");
        }
    }

    /** 'select', 'multiple' or 'toggle' */
    public function getType(): string
    {
        return $this->type;
    }

    public function isMultiple(): bool
    {
        return $this->type === 'multiple';
    }

    public function isToggle(): bool
    {
        return $this->type === 'toggle';
    }

    /**
     * Narrow the query with what the browser sent, if it is something this filter
     * offers: one of the options, some of them for multiple(), a truthy value for toggle().
     * Anything else is ignored, in whole for a select and per value for a multiple.
     */
    public function apply(Builder $query, mixed $value): void
    {
        // Nothing chosen: leave the query alone without resolving the options (a query of their own).
        if (blank($value)) {
            return;
        }

        if ($this->type === 'toggle') {
            if (! $this->isOn($value)) {
                return;
            }

            $this->assertUsable();

            ($this->query)($query, true);

            return;
        }

        if ($this->type === 'multiple') {
            $values = $this->known(is_array($value) ? $value : [$value]);

            if ($values === []) {
                return;
            }

            if ($this->query) {
                ($this->query)($query, $values);

                return;
            }

            $query->whereIn($query->qualifyColumn($this->name), $values);

            return;
        }

        if (is_array($value) || ($values = $this->known([$value])) === []) {
            return;
        }

        if ($this->query) {
            ($this->query)($query, $values[0]);

            return;
        }

        $query->where($query->qualifyColumn($this->name), $values[0]);
    }

    /**
     * The option keys among the given values, as the options spell them (an id offered
     * as an int comes back an int even when the URL sent "3"), without duplicates.
     *
     * @param  list<mixed>  $values
     * @return list<string|int>
     */
    protected function known(array $values): array
    {
        $values = array_filter($values, fn ($value) => (is_int($value) || is_string($value)) && ! blank($value));

        if ($values === []) {
            return [];
        }

        $options = $this->getOptions();
        $known = [];

        foreach ($values as $value) {
            if (! array_key_exists($value, $options)) {
                continue;
            }

            foreach (array_keys($options) as $key) {
                if ((string) $key === (string) $value && ! in_array($key, $known, true)) {
                    $known[] = $key;
                }
            }
        }

        return $known;
    }

    protected function isOn(mixed $value): bool
    {
        return is_bool($value) ? $value : (is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }
}

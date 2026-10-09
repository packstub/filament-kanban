<?php

namespace Packstub\Kanban;

use BackedEnum;
use DateTimeInterface;
use Filament\Support\Contracts\ScalableIcon;
use Filament\Support\Enums\IconSize;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

/**
 * What a card shows. Plain data, rendered by the browser: a board of a few hundred
 * cards is a few kilobytes of JSON instead of a few hundred Blade components.
 *
 *   ┌──────────────────────────────┐
 *   │ eyebrow              aside   │   RO-01338            RON 154.90
 *   │ title                        │   Alexandru Radu
 *   │ description                  │   Two boxes, leave them at the gate
 *   │ [badge] [badge]  meta · due  │   [COD] [☎ call]    🇷🇴 · Oct 7
 *   │ ▓▓▓▓▓▓░░░░ progress          │
 *   └──────────────────────────────┘
 *
 * @implements Arrayable<string, mixed>
 */
class Card implements Arrayable
{
    protected ?string $eyebrow = null;

    protected ?string $title = null;

    protected ?string $aside = null;

    protected ?string $description = null;

    /** @var array{value: float, label: string}|null */
    protected ?array $progress = null;

    /** @var array{date: string, label: string}|null */
    protected ?array $due = null;

    /** @var list<string> */
    protected array $meta = [];

    /** @var list<array{label: string, color: ?string, icon?: string}>  icon is the icon's name */
    protected array $badges = [];

    protected bool $draggable = true;

    protected ?string $url = null;

    protected ?string $accent = null;

    protected ?string $search = null;

    /** @var list<array{url: ?string, name: ?string}> */
    protected array $avatars = [];

    /** @var list<string>|null */
    protected ?array $actions = null;

    public static function make(): static
    {
        return new static;
    }

    /** Small, monospaced line above the title: a reference number, a key. */
    public function eyebrow(?string $text): static
    {
        $this->eyebrow = $text;

        return $this;
    }

    public function title(?string $text): static
    {
        $this->title = $text;

        return $this;
    }

    /** Right-aligned next to the eyebrow: an amount, a due date. */
    public function aside(?string $text): static
    {
        $this->aside = $text;

        return $this;
    }

    /** A muted line under the title, clamped to two lines. */
    public function description(?string $text): static
    {
        $this->description = $text;

        return $this;
    }

    /**
     * A thin bar in the card's foot. Two numbers, `progress(3, 5)`, draw 3/5 and say so
     * on hover; a total of null or 0 (`progress($done, $deal->steps_total)` with nothing
     * to count) draws no bar. One number, `progress(0.6)`, is a fraction between 0 and 1,
     * clamped. A full bar takes the panel's success colour.
     */
    public function progress(int|float|null $done, ?int $total = null): static
    {
        $fraction = func_num_args() < 2;

        if ($done === null || (! $fraction && ! $total)) {
            $this->progress = null;

            return $this;
        }

        $value = max(0.0, min(1.0, $fraction ? (float) $done : $done / $total));

        $this->progress = [
            'value' => round($value, 4),
            'label' => $fraction ? round($value * 100).'%' : "{$done}/{$total}",
        ];

        return $this;
    }

    /**
     * A date in the card's foot, coloured by the browser: danger once the day is past,
     * warning on the day. Only the calendar day travels, in the timezone of the date
     * given (setTimezone() a datetime first). The label defaults to the date in
     * `config('app.date_format')` (or `M j`); pass your own for "Tomorrow", "in 3 days"…
     */
    public function due(?DateTimeInterface $date, ?string $label = null): static
    {
        if ($date === null) {
            $this->due = null;

            return $this;
        }

        $date = Carbon::instance($date);

        $this->due = [
            'date' => $date->toDateString(),
            'label' => $label ?? $date->translatedFormat(config('app.date_format') ?? 'M j'),
        ];

        return $this;
    }

    /** @param  array<int, string|null|false>  $parts  empty parts are dropped */
    public function meta(array $parts): static
    {
        $this->meta = array_values(array_filter($parts, fn ($p) => filled($p)));

        return $this;
    }

    /**
     * A small coloured tag; color is a Tailwind-ish name or any CSS colour. The icon
     * (a Heroicon name or enum) is drawn before the label. The card carries its name
     * only; the board sends each icon's SVG once (see Board::getIcons()).
     */
    public function badge(?string $label, ?string $color = null, bool $condition = true, string|BackedEnum|null $icon = null): static
    {
        if ($condition && filled($label)) {
            $badge = ['label' => $label, 'color' => $color];

            $name = match (true) {
                $icon instanceof ScalableIcon => $icon->getIconForSize(IconSize::Medium),
                $icon instanceof BackedEnum => $icon->value,
                default => $icon,
            };

            if (filled($name)) {
                $badge['icon'] = (string) $name;
            }

            $this->badges[] = $badge;
        }

        return $this;
    }

    /**
     * Whether this card may be dragged (or reordered) at all. Decide it from the record:
     * a locked deal, a task that is not the user's. The server refuses the move too.
     */
    public function draggable(bool $condition = true): static
    {
        $this->draggable = $condition;

        return $this;
    }

    /** The inverse of draggable(): a locked card stays where it is. */
    public function locked(bool $condition = true): static
    {
        $this->draggable = ! $condition;

        return $this;
    }

    public function isDraggable(): bool
    {
        return $this->draggable;
    }

    /** Clicking the card opens this URL. */
    public function url(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /** A thin coloured edge on the left: flags a card that needs attention. */
    public function accent(?string $color): static
    {
        $this->accent = $color;

        return $this;
    }

    /** Extra text the instant (in-browser) search matches on, besides what the card shows. */
    public function searchText(?string $text): static
    {
        $this->search = $text;

        return $this;
    }

    /**
     * A round picture in the card's foot: an assignee, an owner. Without a URL the
     * initials of the name are drawn. Call it again for more people.
     */
    public function avatar(?string $url, ?string $name = null): static
    {
        if (filled($url) || filled($name)) {
            $this->avatars[] = ['url' => $url, 'name' => $name];
        }

        return $this;
    }

    /**
     * The board's card actions this card offers, by name (default: all of them).
     * The server still checks each action's own rules when it is run.
     *
     * @param  list<string>  $names
     */
    public function actions(array $names): static
    {
        $this->actions = array_values($names);

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $card = array_filter([
            'eyebrow' => $this->eyebrow,
            'title' => $this->title,
            'aside' => $this->aside,
            'description' => $this->description,
            'progress' => $this->progress,
            'due' => $this->due,
            'meta' => $this->meta,
            'badges' => $this->badges,
            'url' => $this->url,
            'accent' => $this->accent,
            'search' => $this->search,
            'avatars' => $this->avatars,
        ], fn ($v) => $v !== null && $v !== []);

        // An empty list is meaningful here: this card offers no actions.
        if ($this->actions !== null) {
            $card['actions'] = $this->actions;
        }

        // Absent means draggable; only a locked card says so.
        if (! $this->draggable) {
            $card['draggable'] = false;
        }

        return $card;
    }
}

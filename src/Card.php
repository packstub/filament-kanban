<?php

namespace Packstub\Kanban;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What a card shows. Plain data, rendered by the browser: a board of a few hundred
 * cards is a few kilobytes of JSON instead of a few hundred Blade components.
 *
 *   ┌──────────────────────────────┐
 *   │ eyebrow              aside   │   RO-01338            RON 154.90
 *   │ title                        │   Alexandru Radu
 *   │ [badge] [badge]  meta · meta │   [COD] [☎ call]    🇷🇴 · Casa Verde.ro
 *   └──────────────────────────────┘
 *
 * @implements Arrayable<string, mixed>
 */
class Card implements Arrayable
{
    protected ?string $eyebrow = null;

    protected ?string $title = null;

    protected ?string $aside = null;

    /** @var list<string> */
    protected array $meta = [];

    /** @var list<array{label: string, color: ?string}> */
    protected array $badges = [];

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

    /** @param  array<int, string|null|false>  $parts  empty parts are dropped */
    public function meta(array $parts): static
    {
        $this->meta = array_values(array_filter($parts, fn ($p) => filled($p)));

        return $this;
    }

    /** A small coloured tag; color is a Tailwind-ish name or any CSS colour. */
    public function badge(?string $label, ?string $color = null, bool $condition = true): static
    {
        if ($condition && filled($label)) {
            $this->badges[] = ['label' => $label, 'color' => $color];
        }

        return $this;
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

        return $card;
    }
}

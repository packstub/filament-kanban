# Cards

A card is shaped in one closure that receives the record and returns a `Card`:

```php
->card(fn (Deal $deal) => Card::make()
    ->eyebrow($deal->reference)
    ->title($deal->company)
    ->aside(Number::currency($deal->amount, 'USD'))
    ->badge('hot', 'orange', $deal->is_hot)
    ->badge('overdue', 'red', $deal->close_at?->isPast())
    ->meta([$deal->title, $deal->close_at?->format('M j')])
    ->avatar($deal->owner?->avatar_url, $deal->owner?->name)
    ->url(DealResource::getUrl('edit', ['record' => $deal])))
```

```
┌──────────────────────────────────┐
│ eyebrow                   aside  │   D-1058                $34,000
│ title                            │   Nimbus Cloud
│ [badge] [badge]  meta · meta  ◐  │   [hot]  Partner program · Oct 7   JD
└──────────────────────────────────┘
```

Cards are plain data drawn in the browser, so a board of a few hundred cards is a few kilobytes of JSON rather than a few hundred Blade components. Eager-load what the closure touches (`->query(fn () => Deal::query()->with('owner'))`).

| Method | What it shows |
| --- | --- |
| `eyebrow()` | A small monospaced line above the title: a reference, a key. |
| `title()` | The main line. |
| `aside()` | Right-aligned next to the eyebrow: an amount, a date. |
| `badge($label, $color, $condition = true)` | A small coloured tag; call it once per badge. |
| `meta([...])` | A muted line of parts joined with `·`; empty parts are dropped. |
| `avatar($url, $name)` | A round picture at the end of the foot; without a URL, the initials of the name. Call it again for more people. |
| `accent($color)` | A coloured left edge, for a card that needs attention. |
| `url()` | Clicking the card opens this URL (unless a [click action](actions.md#open-a-card-in-a-modal) is set; a modified click still opens the URL). |
| `searchText()` | Extra words the instant search matches, besides what the card shows. |
| `actions([...])` | Which of the board's [card actions](actions.md) this card offers, by name. |

Colours take the same names as columns (`gray`, `red`, `amber`, `emerald`, `primary`, `danger`, …) or any CSS colour.

## Search on cards

Typing in the search box filters the loaded cards instantly on everything a card shows (and its `searchText()`), then asks the server for the matching cards of every column with the board's `searchable()` attributes. See [Configuration](configuration.md#search).

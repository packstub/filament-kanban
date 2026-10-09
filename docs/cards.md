# Cards

A card is shaped in one closure that receives the record and returns a `Card`:

```php
->card(fn (Deal $deal) => Card::make()
    ->eyebrow($deal->reference)
    ->title($deal->company)
    ->aside(Number::currency($deal->amount, 'USD'))
    ->description($deal->summary)
    ->badge('hot', 'orange', $deal->is_hot)
    ->badge('call', 'sky', $deal->needs_call, icon: 'heroicon-m-phone')
    ->meta([$deal->title])
    ->due($deal->close_at)
    ->progress($deal->steps_done, $deal->steps_total)
    ->avatar($deal->owner?->avatar_url, $deal->owner?->name)
    ->locked($deal->is_closed)
    ->url(DealResource::getUrl('edit', ['record' => $deal])))
```

```
┌──────────────────────────────────┐
│ eyebrow                   aside  │   D-1058                $34,000
│ title                            │   Nimbus Cloud
│ description                      │   Renewal, two seats more than last year
│ [badge] [badge]  meta · due   ◐  │   [hot] [☎ call]  Partner program · Oct 7   JD
│ ▓▓▓▓▓▓▓░░░░░░░ progress          │
└──────────────────────────────────┘
```

Cards are plain data drawn in the browser, so a board of a few hundred cards is a few kilobytes of JSON rather than a few hundred Blade components. Eager-load what the closure touches (`->query(fn () => Deal::query()->with('owner'))`).

| Method | What it shows |
| --- | --- |
| `eyebrow()` | A small monospaced line above the title: a reference, a key. |
| `title()` | The main line. |
| `aside()` | Right-aligned next to the eyebrow: an amount, a date. |
| `description()` | A muted line under the title, clamped to two lines. |
| `badge($label, $color, $condition = true, icon: ...)` | A small coloured tag; call it once per badge. The icon (a Heroicon name or enum) is drawn before the label. |
| `meta([...])` | A muted line of parts joined with `·`; empty parts are dropped. |
| `due($date, $label = null)` | A date in the foot with a calendar glyph, coloured `danger` once it is past and `warning` on the day. |
| `progress($done, $total = null)` | A thin bar at the bottom of the foot: `progress(3, 5)` is 3/5 (the hover says so), `progress(0.6)` is 60 %. Full takes the `success` colour. |
| `avatar($url, $name)` | A round picture at the end of the foot; without a URL, the initials of the name. Call it again for more people. |
| `accent($color)` | A coloured left edge, for a card that needs attention. |
| `url()` | Clicking the card opens this URL (unless a [click action](actions.md#open-a-card-in-a-modal) is set; a modified click still opens the URL). |
| `searchText()` | Extra words the instant search matches, besides what the card shows. |
| `actions([...])` | Which of the board's [card actions](actions.md) this card offers, by name. |
| `locked()` / `draggable()` | Whether this card may be moved at all; see [Locking a card](#locking-a-card). |

Colours take the same names as columns (`gray`, `red`, `amber`, `emerald`, `primary`, `danger`, …) or any CSS colour.

### Due dates

`due()` takes any `DateTimeInterface`. The label defaults to the date in `config('app.date_format')`, or `M j` (`Oct 7`) when the app has none; pass your own for `due($date, 'Tomorrow')` or `due($date, $date->diffForHumans())`. The colour is decided in the browser from the ISO date, in the viewer's own calendar day, so a card turns red at the viewer's midnight, not the server's.

### Progress

With a total, `progress($done, $total)` draws `done / total` and shows `3/5` on hover. With one number, it is a fraction between 0 and 1 (`0.6` is 60 %); values outside the range are clamped. The bar is `primary` and turns `success` when full.

## Locking a card

Column rules (`draggable()`, `droppable()`, `accepts()`) apply to every card in a column. For a single card that must not move, a closed deal, a task that belongs to someone else, a record with open checks, decide it on the card, where the record is at hand:

```php
->card(fn (Task $task) => Card::make()
    ->title($task->title)
    ->locked($task->assignee_id !== auth()->id()))
```

A locked card shows a small lock before its title, cannot be dragged, and offers no "Move to" in its menu (its actions stay). The server reads the card again on every move and refuses with "This card cannot be moved." whatever the browser sent, for a reorder inside the column too. `draggable(false)` is the same as `locked()`. Nothing is evaluated per card beyond the card closure, which already runs for every card drawn.

## Search on cards

Typing in the search box filters the loaded cards instantly on everything a card shows (its description and due label included, and its `searchText()`), then asks the server for the matching cards of every column with the board's `searchable()` attributes. See [Configuration](configuration.md#search).

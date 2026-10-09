<?php

namespace Packstub\Kanban\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A card changed column, or lane, on a board (reordering inside a column is not a
 * move). With swimlanes, $lane is the lane the card changed to ('' for the
 * unassigned lane); null when the lane did not change.
 */
class CardMoved
{
    use Dispatchable;

    public function __construct(
        public readonly Model $record,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $board = null,
        public readonly ?string $lane = null,
    ) {}
}

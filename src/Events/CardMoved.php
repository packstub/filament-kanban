<?php

namespace Packstub\Kanban\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** A card changed column on a board (reordering inside a column is not a move). */
class CardMoved
{
    use Dispatchable;

    public function __construct(
        public readonly Model $record,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $board = null,
    ) {}
}

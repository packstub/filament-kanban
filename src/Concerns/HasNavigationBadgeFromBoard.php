<?php

namespace Packstub\Kanban\Concerns;

/**
 * An opt-in navigation badge on a standalone KanbanPage: the number of cards on the
 * board (one grouped query over the visible columns), none when the board is empty.
 * Off by default, so building the navigation never touches the database.
 */
trait HasNavigationBadgeFromBoard
{
    protected static bool $navigationBadgeFromBoard = false;

    public static function getNavigationBadge(): ?string
    {
        if (! static::$navigationBadgeFromBoard) {
            return parent::getNavigationBadge();
        }

        return ($count = static::getBoardCount()) > 0 ? (string) $count : null;
    }
}

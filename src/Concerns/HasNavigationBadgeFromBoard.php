<?php

namespace Packstub\Kanban\Concerns;

/**
 * An opt-in navigation badge with the number of cards on the board (one grouped
 * query over the visible columns). Off by default, so building the navigation
 * never touches the database.
 */
trait HasNavigationBadgeFromBoard
{
    protected static bool $navigationBadgeFromBoard = false;

    public static function getNavigationBadge(): ?string
    {
        if (! static::$navigationBadgeFromBoard) {
            return parent::getNavigationBadge();
        }

        return (string) app(static::class)->getKanban()->getTotalCount();
    }
}

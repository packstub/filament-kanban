<?php

namespace Packstub\Kanban\Concerns;

use Filament\Resources\Pages\PageRegistration;
use Packstub\Kanban\Pages\KanbanResourcePage;

/**
 * For a resource: its navigation badge is the number of cards on its board page
 * (see getKanbanPage()), none when the board is empty; the board's own query and
 * visible columns, without the search, filters or columns a user hid in the browser. A user
 * who may open the resource but not its board page gets the resource's own badge.
 * Filament puts the resource in the navigation, not its pages, so the badge lives here.
 */
trait HasKanbanNavigationBadge
{
    public static function getNavigationBadge(): ?string
    {
        $page = static::getKanbanPage();

        if ($page === null || ! $page::canAccess()) {
            return parent::getNavigationBadge();
        }

        // The sidebar is on every page of the panel: a board that cannot count (a kanban()
        // that needs mount()) is reported and shows no badge, never a broken panel.
        $count = rescue(fn () => $page::getBoardCount(), 0, report: true);

        return $count > 0 ? (string) $count : null;
    }

    /**
     * The resource's board page: the one KanbanAction links to (`kanban`, then `board`),
     * else the first KanbanResourcePage in getPages().
     *
     * @return class-string<KanbanResourcePage>|null
     */
    public static function getKanbanPage(): ?string
    {
        $pages = static::getPages();
        $isBoard = fn ($registration) => $registration instanceof PageRegistration && is_subclass_of($registration->getPage(), KanbanResourcePage::class);

        foreach (['kanban', 'board'] as $key) {
            if (isset($pages[$key]) && $isBoard($pages[$key])) {
                return $pages[$key]->getPage();
            }
        }

        foreach ($pages as $registration) {
            if ($isBoard($registration)) {
                return $registration->getPage();
            }
        }

        return null;
    }
}

<?php

namespace Packstub\Kanban\Concerns;

use Filament\Resources\Pages\PageRegistration;
use Packstub\Kanban\Pages\KanbanResourcePage;

/**
 * For a resource: its navigation badge is the number of cards on its board page
 * (the first KanbanResourcePage in getPages()), none when the board is empty.
 * Filament puts the resource in the navigation, not its pages, so the badge lives here.
 */
trait HasKanbanNavigationBadge
{
    public static function getNavigationBadge(): ?string
    {
        $page = static::getKanbanPage();

        if ($page === null) {
            return parent::getNavigationBadge();
        }

        return ($count = $page::getBoardCount()) > 0 ? (string) $count : null;
    }

    /** @return class-string<KanbanResourcePage>|null the resource's board page */
    public static function getKanbanPage(): ?string
    {
        foreach (static::getPages() as $registration) {
            if ($registration instanceof PageRegistration && is_subclass_of($registration->getPage(), KanbanResourcePage::class)) {
                return $registration->getPage();
            }
        }

        return null;
    }
}

<?php

namespace Packstub\Kanban\Actions;

use Filament\Actions\Action;

/**
 * A "Board" link to a resource's Kanban page, for a ListRecords page's header: the
 * Table / Board switch. The resource is the page's own; the board page is the
 * resource's `kanban` page, or `board` when there is no `kanban`.
 */
class KanbanAction extends Action
{
    use ResolvesResource;

    protected ?string $page = null;

    public static function getDefaultName(): ?string
    {
        return 'kanban';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('packstub-kanban::kanban.board'));
        $this->icon('heroicon-o-view-columns');
        $this->visible(fn (): bool => $this->getPage() !== null);
        $this->url(fn (): string => $this->getResource()::getUrl($this->getPage()));
    }

    /** The key of the board page in the resource's getPages(). */
    public function page(?string $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function getPage(): ?string
    {
        if ($this->page !== null) {
            return $this->page;
        }

        $resource = $this->getResource();

        foreach (['kanban', 'board'] as $page) {
            if ($resource::hasPage($page)) {
                return $page;
            }
        }

        return null;
    }
}

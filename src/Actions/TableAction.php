<?php

namespace Packstub\Kanban\Actions;

use Filament\Actions\Action;

/** A "Table" link back to the resource's list: the board page's default header action. */
class TableAction extends Action
{
    use ResolvesResource;

    protected string $page = 'index';

    public static function getDefaultName(): ?string
    {
        return 'table';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('packstub-kanban::kanban.table'));
        $this->icon('heroicon-o-table-cells');
        $this->url(fn (): string => $this->getPageUrl($this->page));
    }

    /** Hidden without a list page the user may open, whatever visible() or hidden() the app adds. */
    public function isHidden(): bool
    {
        return ! $this->canOpenPage($this->page) || parent::isHidden();
    }

    /** The key of the list page in the resource's getPages(). */
    public function page(string $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function getPage(): string
    {
        return $this->page;
    }
}

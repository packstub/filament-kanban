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
        $this->visible(fn (): bool => $this->getResource()::hasPage($this->page));
        $this->url(fn (): string => $this->getResource()::getUrl($this->page));
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

<?php

namespace Packstub\Kanban\Pages;

use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Packstub\Kanban\Concerns\InteractsWithKanban;

/** A resource page holding one board, next to the resource's list. Implement kanban(Board $board). */
abstract class KanbanResourcePage extends Page
{
    use InteractsWithKanban;

    protected string $view = 'packstub-kanban::pages.kanban';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getExtraBodyAttributes(): array
    {
        return $this->getKanban()->hasFocusMode()
            ? ['class' => trim(($this->extraBodyAttributes['class'] ?? '').' pk-focus')] + $this->extraBodyAttributes
            : $this->extraBodyAttributes;
    }
}

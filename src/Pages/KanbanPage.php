<?php

namespace Packstub\Kanban\Pages;

use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Packstub\Kanban\Concerns\InteractsWithKanban;

/** A standalone panel page holding one board. Implement kanban(Board $board). */
abstract class KanbanPage extends Page
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

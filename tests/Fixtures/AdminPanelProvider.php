<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;

/** A panel with the resource fixture, so resource URLs and header actions resolve in tests. */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->default()
            ->resources([TaskResource::class])
            ->pages([StandaloneBoardPage::class]);
    }
}

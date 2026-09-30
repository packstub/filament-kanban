<?php

namespace Packstub\Kanban;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class KanbanServiceProvider extends PackageServiceProvider
{
    public static string $name = 'packstub-kanban';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasViews(static::$name)
            ->hasTranslations();
    }

    public function packageBooted(): void
    {
        // Loaded by the board itself (x-load / x-load-css), so pages without a board pay nothing.
        FilamentAsset::register([
            AlpineComponent::make('kanban', __DIR__.'/../resources/dist/kanban.js'),
            Css::make('kanban', __DIR__.'/../resources/dist/kanban.css')->loadedOnRequest(),
        ], 'packstub/filament-kanban');
    }
}

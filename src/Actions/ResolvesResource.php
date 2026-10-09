<?php

namespace Packstub\Kanban\Actions;

use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Resource;
use LogicException;

trait ResolvesResource
{
    /** @var class-string<\Filament\Resources\Resource>|null */
    protected ?string $resource = null;

    /** @param  class-string<\Filament\Resources\Resource>|null  $resource  defaults to the page's own */
    public function resource(?string $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    /** @return class-string<\Filament\Resources\Resource> */
    public function getResource(): string
    {
        if ($this->resource !== null) {
            return $this->resource;
        }

        $livewire = $this->getLivewire();

        if ($livewire instanceof ResourcePage) {
            return $livewire::getResource();
        }

        throw new LogicException(static::class.' needs a resource: put it on a resource page or call ->resource(TaskResource::class).');
    }

    /** Whether the resource has this page and the user may open it (the page's own canAccess()). */
    protected function canOpenPage(?string $page): bool
    {
        if (blank($page)) {
            return false;
        }

        $registration = $this->getResource()::getPages()[$page] ?? null;

        return $registration !== null && $registration->getPage()::canAccess();
    }

    /** The page's URL; a nested resource's parent parameters are taken from the current route. */
    protected function getPageUrl(string $page): string
    {
        return $this->getResource()::getUrl($page, shouldGuessMissingParameters: true);
    }
}

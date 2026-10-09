<?php

namespace Packstub\Kanban\Actions;

use Filament\Resources\Pages\Page as ResourcePage;
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
}

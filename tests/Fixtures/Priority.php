<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Priority: int implements HasColor, HasLabel
{
    case Low = 1;
    case High = 9;

    public function getLabel(): string
    {
        return match ($this) {
            self::Low => 'Low priority',
            self::High => 'High priority',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::High => 'red',
        };
    }
}

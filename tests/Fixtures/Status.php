<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Status: string implements HasColor, HasLabel
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Done = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::Doing => 'In progress',
            self::Done => 'Done',
        };
    }

    public function getColor(): string|array
    {
        return match ($this) {
            self::Todo => 'gray',
            self::Doing => Color::Amber,
            self::Done => 'success',
        };
    }
}

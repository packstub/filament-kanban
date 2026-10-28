<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Events\BoardChanged;
use Packstub\Kanban\Lane;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;

/*
 * What the browser gets is bounded on the server: colours and URLs that land in a
 * style or href, the order a drop sends, the realtime origin token.
 */

it('keeps only colour shapes for a badge, an accent, a column and a lane', function () {
    $safe = ['sky', 'primary', 'brand-2', '#abc', '#aabbccdd', 'rgb(1, 2, 3)', 'rgba(1 2 3 / 50%)', 'hsl(200 50% 50%)', 'oklch(70% 0.1 200)', 'color(display-p3 1 0 0)', 'var(--brand-500)'];
    $unsafe = ['red; background: url(x)', 'url(javascript:1)', 'rgb(var(--x))', 'red }', '#ggg', 'var(--a; b)', '', ' '];

    foreach ($safe as $color) {
        expect(Column::safeColor($color))->toBe($color)
            ->and(Column::make('a')->color($color)->getColor())->toBe($color)
            ->and(Lane::make('a')->color($color)->getColor())->toBe($color)
            ->and(Card::make()->badge('x', $color)->toArray()['badges'][0]['color'])->toBe($color)
            ->and(Card::make()->accent($color)->toArray()['accent'])->toBe($color);
    }

    foreach ($unsafe as $color) {
        expect(Column::safeColor($color))->toBeNull()
            ->and(Column::make('a')->color($color)->getColor())->toBeNull()
            ->and(Lane::make('a')->color($color)->getColor())->toBeNull()
            ->and(Card::make()->badge('x', $color)->toArray()['badges'][0]['color'])->toBeNull()
            ->and(Card::make()->accent($color)->toArray())->not->toHaveKey('accent');
    }

    expect(Column::safeColor(' sky '))->toBe('sky')
        ->and(Column::safeColor(null))->toBeNull()
        ->and(Column::safeColor(['x']))->toBeNull()
        ->and(Lane::make('a')->color([500 => '#abc; x'])->getColor())->toBeNull()
        ->and(Lane::make('a')->color([500 => '#abc'])->getColor())->toBe('#abc');
});

it('keeps only http(s), mailto, tel and relative URLs on a card', function () {
    foreach (['https://example.test/a?b=c#d', 'http://x', 'mailto:a@b.c', 'TEL:+40', '/tasks/1', './a', '../a', '#top', '?page=2', 'tasks/1'] as $url) {
        expect(Card::safeUrl($url))->toBe($url)
            ->and(Card::make()->url($url)->toArray()['url'])->toBe($url);
    }

    foreach (['javascript:alert(1)', 'JavaScript:alert(1)', "java\tscript:alert(1)", " \x01javascript:alert(1)", 'data:text/html,x', 'vbscript:x', '//evil.test/a', '\\\\evil.test', '/\\evil.test', '', '   ', null] as $url) {
        expect(Card::safeUrl($url))->toBeNull()
            ->and(Card::make()->url($url)->toArray())->not->toHaveKey('url');
    }
});

it('drops an order longer than ten pages of the column and keeps its scalar entries only', function () {
    $a = task('A', 'todo', ['sort' => 1]);
    $b = task('B', 'todo', ['sort' => 2]);

    $board = Board::make()->query(fn () => Task::query())->columns([Column::make('todo'), Column::make('doing')])->reorderable('sort')->perColumn(1);

    // Ten pages at most: eleven entries is no order the browser drew, so the move saves and the positions stay.
    $board->move((string) $b->id, 'todo', array_merge([(string) $b->id, (string) $a->id], array_fill(0, 9, 'x')));
    expect($a->fresh()->sort)->toBe(1)->and($b->fresh()->sort)->toBe(2);

    $board->move((string) $b->id, 'todo', [(string) $b->id, ['nested'], null, '', (string) $a->id]);
    expect($b->fresh()->sort)->toBe(1)->and($a->fresh()->sort)->toBe(2);

    // A bulk move past the cap is refused before any query.
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    Livewire::test(TaskBoard::class)
        ->call('kanbanMoveMany', array_map('strval', range(1, Board::MAX_SELECTION + 1)), 'doing')
        ->assertReturned(fn ($result) => $result['ok'] === false && str_contains($result['message'], (string) Board::MAX_SELECTION));
});

it('echoes the realtime origin token only in the shape the browser generates', function () {
    Event::fake([BoardChanged::class]);
    $task = task('Build');

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->key('tasks')->broadcast(fn () => 'team.1.kanban');
        }
    };

    Livewire::test($component::class)
        ->call('kanbanMove', (string) $task->id, 'doing', null, '', [], null, '<script>alert(1)</script>')
        ->assertReturned(fn ($result) => $result['ok'] === true)
        ->call('kanbanMove', (string) $task->id, 'done', null, '', [], null, 'k7x2mq1p9z');

    $origins = [];
    Event::assertDispatched(BoardChanged::class, function (BoardChanged $e) use (&$origins) {
        $origins[] = $e->broadcastWith()['origin'];

        return true;
    });

    expect($origins)->toBe([null, 'k7x2mq1p9z'])
        ->and(str_repeat('a', 33))->not->toMatch('/^[a-z0-9]{1,32}$/i');
});

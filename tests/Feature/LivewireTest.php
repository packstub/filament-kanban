<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Packstub\Kanban\Board;
use Packstub\Kanban\Exceptions\MoveRejected;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;

beforeEach(function () {
    TaskBoard::$visible = null;
    TaskBoard::$canShip = true;
});

it('renders the board with its state as JSON for the browser', function () {
    task('Write docs');

    Livewire::test(TaskBoard::class)
        ->assertSeeHtml('x-data="packstubKanban(')
        ->assertSeeHtml('packstub/filament-kanban/components/kanban.js')
        ->assertSee('Write docs')
        ->assertSeeHtml('wire:ignore')
        ->assertSeeHtml('<style>.pk { visibility: hidden; }</style>')
        ->assertSeeHtml('aria-live="polite"')
        ->assertSeeHtml('role="list"')
        ->assertSeeHtml('role="tablist"');
});

it('moves a card without re-rendering and answers with the card', function () {
    $task = task('Build');

    Livewire::test(TaskBoard::class)
        ->call('kanbanMove', (string) $task->id, 'doing')
        ->assertReturned(fn ($result) => $result['ok'] === true && $result['card']['title'] === 'Build')
        ->assertNoRedirect();

    expect($task->fresh()->status)->toBe('doing');
});

it('answers a refused move with the reason instead of an error', function () {
    $task = task('Build', 'doing');
    TaskBoard::$canShip = false;

    Livewire::test(TaskBoard::class)
        ->call('kanbanMove', (string) $task->id, 'done')
        ->assertReturned(fn ($result) => $result['ok'] === false && str_contains($result['message'], 'cannot be moved'));

    expect($task->fresh()->status)->toBe('doing');
});

it('keeps an exception thrown while saving out of the notification: reported, answered with a generic message', function () {
    Exceptions::fake();
    $task = task('Build');
    $other = task('Review');

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->moveUsing(function (Task $task, string $to) {
                throw_if($task->title === 'Review', new MoveRejected('Needs a review first.'));

                // What a database refusal (a check constraint, a strict-mode value) looks like.
                throw new QueryException('sqlite', 'update "tasks" set "status" = ? where "id" = ?', [$to, $task->id], new RuntimeException('constraint failed'));
            });
        }
    };

    Livewire::test($component::class)
        ->call('kanbanMove', (string) $task->id, 'doing')
        ->assertReturned(['ok' => false, 'message' => 'The move did not go through.'])
        ->call('kanbanMove', (string) $other->id, 'doing')
        ->assertReturned(['ok' => false, 'message' => 'Needs a review first.']);

    Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'update "tasks"'));
    Exceptions::assertReportedCount(1);

    expect($task->fresh()->status)->toBe('todo')
        ->and($other->fresh()->status)->toBe('todo');
});

it('never moves into a column this user cannot see', function () {
    $task = task('Build');
    TaskBoard::$visible = ['todo'];

    Livewire::test(TaskBoard::class)
        ->call('kanbanMove', (string) $task->id, 'doing')
        ->assertReturned(fn ($result) => $result['ok'] === false);

    expect($task->fresh()->status)->toBe('todo');
});

it('refreshes with a search and loads more cards', function () {
    task('Alpha');
    task('Beta');

    Livewire::test(TaskBoard::class)
        ->call('kanbanRefresh', 'alp')
        ->assertReturned(fn ($result) => $result['columns'][0]['count'] === 1 && $result['columns'][0]['cards'][0]['title'] === 'Alpha')
        ->call('kanbanMore', 'todo', 1)
        ->assertReturned(fn ($cards) => count($cards) === 1 && $cards[0]['title'] === 'Beta');
});

it('refreshes as many cards as the browser already shows', function () {
    foreach (range(1, 3) as $i) {
        task("Task {$i}");
    }

    Livewire::test(TaskBoard::class)
        ->call('kanbanRefresh', '', [], ['todo' => 3])
        ->assertReturned(fn ($result) => count($result['columns'][0]['cards']) === 3);
});

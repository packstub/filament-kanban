<?php

use Livewire\Livewire;
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
        ->assertSeeHtml('wire:ignore');
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

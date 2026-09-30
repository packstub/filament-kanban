<?php

use Livewire\Livewire;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;

beforeEach(function () {
    TaskBoard::$visible = null;
    TaskBoard::$canShip = true;
    TaskBoard::$doingLimit = null;
});

it('offers the card actions, the click action and the create action to the browser', function () {
    $config = Livewire::test(TaskBoard::class)->instance()->getKanbanConfig();

    expect(array_column($config['cardActions'], 'name'))->toBe(['edit', 'delete', 'bump'])
        ->and($config['cardActions'][1]['color'])->toBe('danger')
        ->and($config['cardAction'])->toBe('edit')
        ->and($config['createAction']['name'])->toBe('create');
});

it('edits a card in a modal and tells the board to refresh', function () {
    $task = task('Draft');

    Livewire::test(TaskBoard::class)
        ->mountAction('edit', ['kanbanRecord' => (string) $task->id])
        ->assertSet('mountedActions.0.data.title', 'Draft')
        ->set('mountedActions.0.data.title', 'Final')
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertDispatched('packstub-kanban-refresh');

    expect($task->fresh()->title)->toBe('Final');
});

it('runs plain card actions on the card record, with the app\'s own visibility rules', function () {
    $task = task('Low', 'todo', ['priority' => 1]);
    $pinned = task('High', 'todo', ['priority' => 9]);

    Livewire::test(TaskBoard::class)
        ->callAction('bump', arguments: ['kanbanRecord' => (string) $task->id])
        ->assertActionHidden('delete', ['kanbanRecord' => (string) $pinned->id])
        ->callAction('delete', arguments: ['kanbanRecord' => (string) $task->id]);

    expect(Task::find($task->id))->toBeNull()
        ->and($pinned->fresh())->not->toBeNull();
});

it('never runs a card action on a record outside the board', function () {
    $hidden = task('Hidden', 'done');
    TaskBoard::$visible = ['todo', 'doing'];

    Livewire::test(TaskBoard::class)
        ->assertActionHidden('bump', ['kanbanRecord' => (string) $hidden->id])
        ->call('mountAction', 'bump', ['kanbanRecord' => (string) $hidden->id]) // what a crafted request would send
        ->call('callMountedAction')
        ->assertNotDispatched('packstub-kanban-refresh');

    expect($hidden->fresh()->priority)->toBe(0);
});

it('creates a card in the column it was asked from', function () {
    Livewire::test(TaskBoard::class)
        ->callAction('create', data: ['title' => 'Fresh'], arguments: ['kanbanColumn' => 'doing'])
        ->assertHasNoErrors()
        ->assertDispatched('packstub-kanban-refresh');

    expect(Task::firstWhere('title', 'Fresh')?->status)->toBe('doing');
});

it('refuses to create in a column that is not creatable, full or unknown', function (string $column) {
    task('Busy', 'doing');
    TaskBoard::$doingLimit = 1;
    TaskBoard::$visible = ['todo', 'doing', 'done'];

    Livewire::test(TaskBoard::class)
        ->assertActionHidden('create', ['kanbanColumn' => $column])
        ->call('mountAction', 'create', ['kanbanColumn' => $column])
        ->set('mountedActions.0.data.title', 'Sneaky')
        ->call('callMountedAction');

    expect(Task::where('title', 'Sneaky')->exists())->toBeFalse();
})->with(['not creatable' => 'done', 'full' => 'doing', 'unknown' => 'archived']);

it('answers a move with the summaries of both columns', function () {
    $task = task('Build', 'todo', ['priority' => 3]);
    task('Other', 'todo', ['priority' => 2]);

    Livewire::test(TaskBoard::class)
        ->call('kanbanMove', (string) $task->id, 'doing')
        ->assertReturned(fn ($result) => $result['summaries'] === ['todo' => '2 pts', 'doing' => '3 pts']);
});

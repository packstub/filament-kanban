<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Packstub\Kanban\Board;
use Packstub\Kanban\Tests\Fixtures\PlainBoard;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;

beforeEach(function () {
    TaskBoard::$visible = null;
    TaskBoard::$canShip = true;
    TaskBoard::$doingLimit = null;
});

it('moves several cards at once, answering the moved ones and the refused ones with their reasons', function () {
    $a = task('A', 'todo', ['priority' => 2]);
    $b = task('B', 'todo', ['priority' => 3]);
    $shipped = task('Shipped', 'done');
    $elsewhere = task('Elsewhere', 'doing');
    TaskBoard::$visible = ['todo', 'doing', 'done'];

    Livewire::test(TaskBoard::class)
        ->call('kanbanMoveMany', [(string) $a->id, (string) $b->id, (string) $shipped->id, '999', (string) $a->id], 'doing')
        ->assertReturned(fn ($result) => $result['ok'] === true
            && array_column($result['moved'], 'title') === ['A', 'B']
            && array_column($result['refused'], 'id') === [(string) $shipped->id, '999']
            && str_contains($result['refused'][0]['message'], 'cannot be moved')
            && str_contains($result['refused'][1]['message'], 'no longer on the board')
            && $result['summaries'] === ['doing' => '5 pts', 'todo' => '0 pts', 'done' => '0 pts']);

    expect($a->fresh()->status)->toBe('doing')
        ->and($b->fresh()->status)->toBe('doing')
        ->and($shipped->fresh()->status)->toBe('done')
        ->and($elsewhere->fresh()->status)->toBe('doing');
});

it('keeps an exception thrown while saving one card out of the answer and moves the others', function () {
    Exceptions::fake();
    $a = task('A');
    $b = task('Broken');

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->moveUsing(function (Task $task, string $to) {
                throw_if($task->title === 'Broken', new QueryException('sqlite', 'update "tasks"', [], new RuntimeException('constraint failed')));
                $task->update(['status' => $to]);
            });
        }
    };

    Livewire::test($component::class)
        ->call('kanbanMoveMany', [(string) $a->id, (string) $b->id], 'doing')
        ->assertReturned(fn ($result) => $result['ok'] && count($result['moved']) === 1 && $result['refused'] === [['id' => (string) $b->id, 'message' => 'The move did not go through.']]);

    Exceptions::assertReportedCount(1);
    expect($a->fresh()->status)->toBe('doing')->and($b->fresh()->status)->toBe('todo');
});

it('runs a bulk action on the selected records that are on the board, and on nothing else', function () {
    $a = task('A');
    $b = task('B');
    $hidden = task('Hidden', 'done');
    TaskBoard::$visible = ['todo', 'doing'];

    Livewire::test(TaskBoard::class)
        ->callAction('bumpAll', arguments: ['kanbanRecords' => [(string) $a->id, (string) $b->id, (string) $hidden->id, '999']])
        ->assertHasNoErrors()
        ->assertDispatched('packstub-kanban-refresh');

    expect($a->fresh()->priority)->toBe(1)
        ->and($b->fresh()->priority)->toBe(1)
        ->and($hidden->fresh()->priority)->toBe(0);
});

it('hides a bulk action while nothing selected is on the board', function () {
    $hidden = task('Hidden', 'done');
    TaskBoard::$visible = ['todo', 'doing'];

    Livewire::test(TaskBoard::class)
        ->assertActionHidden('bumpAll', ['kanbanRecords' => []])
        ->assertActionHidden('bumpAll', ['kanbanRecords' => [(string) $hidden->id]])
        ->call('mountAction', 'bumpAll', ['kanbanRecords' => [(string) $hidden->id]]) // what a crafted request would send
        ->call('callMountedAction')
        ->assertNotDispatched('packstub-kanban-refresh');

    expect($hidden->fresh()->priority)->toBe(0);
});

it('offers the bulk actions to the browser, on components with Filament\'s action system only', function () {
    expect(array_column(Livewire::test(TaskBoard::class)->instance()->getKanbanConfig()['bulkActions'], 'name'))->toBe(['bumpAll'])
        ->and(Livewire::test(PlainBoard::class)->instance()->getKanbanConfig()['bulkActions'])->toBe([]);
});

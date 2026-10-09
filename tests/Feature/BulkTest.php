<?php

use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Packstub\Kanban\Actions\BulkAction;
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

it('gives a bulk action\'s query the same cleaned ids as its records', function () {
    $a = task('A');
    $b = task('B');

    Livewire::test(TaskBoard::class)
        ->callAction('bumpQuery', arguments: ['kanbanRecords' => [(string) $a->id, [(string) $b->id], '']])
        ->assertHasNoErrors();

    expect($a->fresh()->priority)->toBe(1)
        ->and($b->fresh()->priority)->toBe(0); // a nested array is not an id, for the query either
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
    expect(array_column(Livewire::test(TaskBoard::class)->instance()->getKanbanConfig()['bulkActions'], 'name'))->toBe(['bumpAll', 'bumpQuery'])
        ->and(Livewire::test(PlainBoard::class)->instance()->getKanbanConfig()['bulkActions'])->toBe([]);
});

it('ignores ids that are not scalars and caps a selection, without an error', function () {
    $a = task('A');

    Livewire::test(TaskBoard::class)
        ->call('kanbanMoveMany', [[1], ['x' => 2], null, '', (string) $a->id], 'doing')
        ->assertReturned(fn ($result) => array_column($result['moved'], 'id') === [(string) $a->id] && $result['refused'] === [])
        ->call('kanbanMoveMany', array_map('strval', range(1, Board::MAX_SELECTION + 1)), 'doing')
        ->assertReturned(fn ($result) => $result['ok'] === false && $result['moved'] === [] && str_contains($result['message'], (string) Board::MAX_SELECTION))
        ->assertActionHidden('bumpAll', ['kanbanRecords' => [[1], 'nope']])
        ->callAction('bumpAll', arguments: ['kanbanRecords' => [[$a->id], (string) $a->id]])
        ->assertHasNoErrors();

    expect(Board::selectionIds([[1], 2, '2', ' ', null, 3.0]))->toBe(['2', '3'])
        ->and(Board::selectionIds(range(1, Board::MAX_SELECTION + 10)))->toBe([]) // refused as a whole, never trimmed
        ->and(Board::selectionTooLarge(range(1, Board::MAX_SELECTION)))->toBeFalse()
        ->and(Board::selectionTooLarge(range(1, Board::MAX_SELECTION + 1)))->toBeTrue()
        ->and($a->fresh())->toMatchArray(['status' => 'doing', 'priority' => 1]);
});

it('refuses a bulk action past the cap as a whole, like a bulk move', function () {
    $a = task('A');
    $ids = [...array_map('strval', range(1000, 1000 + Board::MAX_SELECTION)), (string) $a->id];

    Livewire::test(TaskBoard::class)
        ->assertActionHidden('bumpAll', ['kanbanRecords' => $ids])
        ->call('mountAction', 'bumpAll', ['kanbanRecords' => $ids])
        ->call('callMountedAction')
        ->assertNotDispatched('packstub-kanban-refresh');

    expect($a->fresh()->priority)->toBe(0)
        ->and(Livewire::test(TaskBoard::class)->instance()->getKanbanConfig()['maxSelection'])->toBe(Board::MAX_SELECTION);
});

it('refuses Filament\'s table bulk actions on the board and names the plugin\'s', function () {
    expect(fn () => Board::make()->bulkActions([DeleteBulkAction::make()]))->toThrow(LogicException::class, 'Packstub\Kanban\Actions\BulkAction')
        ->and(fn () => Board::make()->bulkActions([Filament\Actions\BulkAction::make('x')]))->toThrow(LogicException::class);
});

it('tells a bulk action asked for its records off a board', function () {
    $action = BulkAction::make('loose');

    expect(fn () => $action->getSelectedRecords())->toThrow(LogicException::class, 'Board::bulkActions()')
        ->and(fn () => $action->getSelectedRecordsQuery())->toThrow(LogicException::class, 'Board::bulkActions()');
});

it('offers selection with bulk actions, or when asked for, and not otherwise', function () {
    expect(Livewire::test(TaskBoard::class)->instance()->getKanbanConfig()['selectable'])->toBeTrue()
        ->and(Livewire::test(PlainBoard::class)->instance()->getKanbanConfig()['selectable'])->toBeFalse()
        ->and(Board::make()->isSelectable())->toBeFalse()
        ->and(Board::make()->selectable()->isSelectable())->toBeTrue()
        ->and(Board::make()->bulkActions([BulkAction::make('x')])->selectable(false)->isSelectable())->toBeFalse()
        ->and(Board::make()->selectable(fn () => true)->isSelectable())->toBeTrue();
});

it('keeps the four languages in step', function () {
    $keys = array_keys(require __DIR__.'/../../resources/lang/en/kanban.php');

    foreach (['ro', 'ru', 'de'] as $lang) {
        expect(array_keys(require __DIR__."/../../resources/lang/{$lang}/kanban.php"))->toBe($keys);
    }

    expect($keys)->toContain('bulk_refused_one', 'bulk_limit', 'other');
});

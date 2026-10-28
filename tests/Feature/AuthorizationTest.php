<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Packstub\Kanban\Board;
use Packstub\Kanban\Column;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;
use Packstub\Kanban\Tests\Fixtures\TaskBoardPage;
use Packstub\Kanban\Tests\Fixtures\TaskPolicy;

/*
 * Who may move a card: authorizeMoves(), by default the model policy's `update`
 * (when the model has one) and on a resource page the resource's canEdit().
 */

beforeEach(function () {
    TaskPolicy::$canUpdate = true;
    TaskBoardPage::$query = null;
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(user());
});

function policed(): Board
{
    return Board::make()
        ->query(fn () => Task::query())
        ->columns([Column::make('todo'), Column::make('doing'), Column::make('done')]);
}

it('refuses a move and a bulk move the model policy does not allow, and draws the card locked', function () {
    Gate::policy(Task::class, TaskPolicy::class);
    TaskPolicy::$canUpdate = false;
    $task = task('Build');
    $other = task('Other');

    $test = Livewire::test(TaskBoard::class);

    $test->call('kanbanMove', (string) $task->id, 'doing')
        ->assertReturned(['ok' => false, 'message' => 'You cannot move this card.'])
        ->call('kanbanMoveMany', [(string) $task->id, (string) $other->id], 'doing')
        ->assertReturned(fn ($result) => $result['ok'] === false && $result['moved'] === [] && array_column($result['refused'], 'message') === ['You cannot move this card.', 'You cannot move this card.']);

    expect($task->fresh()->status)->toBe('todo')
        ->and($other->fresh()->status)->toBe('todo');

    $cards = collect(Livewire::test(TaskBoard::class)->instance()->getKanbanConfig()['columns'])->firstWhere('name', 'todo')['cards'];
    expect(array_column($cards, 'draggable'))->toBe([false, false]);

    // A reorder inside the column is a move too.
    expect(fn () => policed()->reorderable('sort')->move((string) $task->id, 'todo', [(string) $other->id, (string) $task->id]))->toThrow('You cannot move this card.')
        ->and($task->fresh()->sort)->toBeNull();
});

it('lets authorizeMoves() decide instead of the policy, and allows moves on a model without a policy', function () {
    $task = task('Build');

    // No policy registered: allowed.
    expect(policed()->move((string) $task->id, 'doing')['id'])->toBe((string) $task->id)
        ->and($task->fresh()->status)->toBe('doing');

    Gate::policy(Task::class, TaskPolicy::class);
    TaskPolicy::$canUpdate = false;

    expect(fn () => policed()->move((string) $task->id, 'done'))->toThrow('You cannot move this card.')
        ->and(policed()->authorizeMoves(true)->move((string) $task->id, 'done')['id'])->toBe((string) $task->id)
        ->and($task->fresh()->status)->toBe('done')
        ->and(policed()->authorizeMoves(fn (Task $record) => $record->title === 'Other')->canMoveRecord($task))->toBeFalse()
        ->and(policed()->authorizeMoves(fn (Task $record) => $record->title === 'Other')->presentCard($task)['draggable'])->toBeFalse()
        ->and(policed()->authorizeMoves(false)->hasMoveAuthorization())->toBeTrue()
        ->and(policed()->hasMoveAuthorization())->toBeFalse();

    // The policy's answer is cached per record and request; authorizeMoves() starts over.
    $board = policed();
    expect($board->canMoveRecord($task))->toBeFalse();
    TaskPolicy::$canUpdate = true;
    expect($board->canMoveRecord($task))->toBeFalse()
        ->and($board->authorizeMoves(null)->canMoveRecord($task))->toBeTrue();
});

it('follows the resource\'s canEdit() on a resource page, unless the board decides itself', function () {
    Gate::policy(Task::class, TaskPolicy::class);
    TaskPolicy::$canUpdate = false;
    $task = task('Build');

    Livewire::test(TaskBoardPage::class)
        ->call('kanbanMove', (string) $task->id, 'doing')
        ->assertReturned(['ok' => false, 'message' => 'You cannot move this card.']);

    expect($task->fresh()->status)->toBe('todo');

    $open = new class extends TaskBoardPage
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->authorizeMoves(true);
        }
    };

    Livewire::test($open::class)
        ->call('kanbanMove', (string) $task->id, 'doing')
        ->assertReturned(fn ($result) => $result['ok'] === true);

    expect($task->fresh()->status)->toBe('doing');
});

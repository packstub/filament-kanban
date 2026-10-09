<?php

use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Events\CardMoved;
use Packstub\Kanban\Exceptions\MoveRejected;
use Packstub\Kanban\Lane;
use Packstub\Kanban\Tests\Fixtures\Priority;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;

function laneBoard(): Board
{
    return Board::make()
        ->query(fn () => Task::query())
        ->sortBy('priority', 'desc')
        ->columns([Column::make('todo'), Column::make('doing')->accepts(['todo']), Column::make('done')->accepts(['doing'])])
        ->card(fn (Task $task) => Card::make()->title($task->title));
}

it('derives the lanes from the data, in first-seen order of the sort, with an unassigned lane for null', function () {
    task('Spec', 'todo', ['priority' => 5, 'assignee' => 'dan']);
    task('Build', 'doing', ['priority' => 9, 'assignee' => 'ana']);
    task('Nobody', 'todo', ['priority' => 1]);

    $lanes = laneBoard()->swimlanes('assignee')->getLanes();

    expect(array_map(fn (Lane $lane) => $lane->toArray(), $lanes))->toBe([
        ['value' => 'ana', 'label' => 'Ana', 'color' => null, 'collapsed' => false],
        ['value' => 'dan', 'label' => 'Dan', 'color' => null, 'collapsed' => false],
        ['value' => '', 'label' => 'Unassigned', 'color' => null, 'collapsed' => false],
    ])->and(laneBoard()->getLanes())->toBeNull()
        ->and(laneBoard()->hasLanes())->toBeFalse();
});

it('takes lanes with labels and colours, from Lane objects, a closure or an enum, and adds an unassigned lane only when cards fall outside them', function () {
    task('Build', 'todo', ['assignee' => 'ana', 'priority' => 9]);

    $given = laneBoard()->swimlanes('assignee', [Lane::make('dan')->label('Dan Ionescu')->color('sky')->collapsed(), Lane::make('ana')->label(fn () => 'Ana Pop')]);

    expect(array_column(array_map(fn (Lane $l) => $l->toArray(), $given->getLanes()), 'label'))->toBe(['Dan Ionescu', 'Ana Pop'])
        ->and($given->getLane('dan')?->toArray())->toMatchArray(['color' => 'sky', 'collapsed' => true])
        ->and(array_column(array_map(fn (Lane $l) => $l->toArray(), laneBoard()->swimlanes('assignee', fn () => [Lane::make('dan')])->getLanes()), 'value'))->toBe(['dan', ''])
        ->and(array_map(fn (Lane $l) => $l->toArray(), laneBoard()->swimlanes('priority', Priority::class)->getLanes()))->toBe([
            ['value' => '1', 'label' => 'Low priority', 'color' => 'gray', 'collapsed' => false],
            ['value' => '9', 'label' => 'High priority', 'color' => 'red', 'collapsed' => false],
        ]);

    task('Odd', 'todo', ['priority' => 4]); // outside the enum

    expect(array_column(array_map(fn (Lane $l) => $l->toArray(), laneBoard()->swimlanes('priority', Lane::fromEnum(Priority::class))->getLanes()), 'value'))->toBe(['1', '9', '']);
});

it('draws the columns with a count per lane and cards carrying their lane, paged per column per lane', function () {
    foreach (range(1, 3) as $i) {
        task("Ana {$i}", 'todo', ['assignee' => 'ana', 'priority' => $i]);
    }
    task('Dan 1', 'todo', ['assignee' => 'dan']);
    task('Loose', 'doing');

    $board = laneBoard()->swimlanes('assignee')->perColumn(2);
    $state = $board->getState();

    expect($state[0])->toMatchArray(['count' => 4, 'counts' => ['ana' => 3, 'dan' => 1]])
        ->and(array_map(fn ($c) => [$c['lane'], $c['title']], $state[0]['cards']))->toBe([['ana', 'Ana 3'], ['ana', 'Ana 2'], ['dan', 'Dan 1']])
        ->and($state[1])->toMatchArray(['count' => 1, 'counts' => ['' => 1]])
        ->and($state[1]['cards'][0])->toMatchArray(['lane' => '', 'title' => 'Loose'])
        ->and($state[2])->toMatchArray(['count' => 0, 'counts' => [], 'cards' => []])
        ->and(array_column($board->getCards('todo', offset: 2, lane: 'ana'), 'title'))->toBe(['Ana 1'])
        ->and(array_column($board->getCards('doing', lane: ''), 'title'))->toBe(['Loose'])
        ->and($board->getState(loaded: ['todo' => ['ana' => 3]])[0]['cards'])->toHaveCount(4);
});

it('keeps the state of a board without swimlanes as it was', function () {
    task('Build');

    $state = laneBoard()->getState();

    expect(array_keys($state[0]))->toBe(['name', 'label', 'color', 'collapsed', 'droppable', 'draggable', 'accepts', 'limit', 'total', 'creatable', 'summary', 'count', 'cards'])
        ->and(array_keys($state[0]['cards'][0]))->toBe(['id', 'title']);

    $config = Livewire::test(TaskBoard::class)->instance()->getKanbanConfig();

    expect($config['lanes'])->toBeNull()
        ->and(Livewire::test(TaskBoard::class)->call('kanbanRefresh')->instance())->not->toBeNull();
});

it('sets the lane on a drop in another lane, also inside the same column, and dispatches CardMoved with it', function () {
    Event::fake([CardMoved::class]);
    $task = task('Build', 'todo', ['assignee' => 'ana']);
    task('Other', 'doing', ['assignee' => 'dan']);
    task('Loose', 'doing');

    $board = laneBoard()->swimlanes('assignee')->key('tasks');

    expect($board->move((string) $task->id, 'doing', null, 'dan'))->toMatchArray(['lane' => 'dan', 'title' => 'Build'])
        ->and($task->fresh())->toMatchArray(['status' => 'doing', 'assignee' => 'dan']);
    Event::assertDispatched(CardMoved::class, fn (CardMoved $e) => $e->from === 'todo' && $e->to === 'doing' && $e->lane === 'dan' && $e->board === 'tasks');

    $board->move((string) $task->id, 'doing', null, '');
    expect($task->fresh()->assignee)->toBeNull();
    Event::assertDispatched(CardMoved::class, fn (CardMoved $e) => $e->from === 'doing' && $e->to === 'doing' && $e->lane === '');

    // The lane is left alone when the browser sent none.
    $task->refresh()->update(['assignee' => 'ana']);
    $board->move((string) $task->id, 'done');
    expect($task->fresh())->toMatchArray(['status' => 'done', 'assignee' => 'ana']);

    // A lane that is not on the board, or a column rule, refuses the move.
    expect(fn () => $board->move((string) $task->id, 'done', null, 'nobody'))->toThrow(MoveRejected::class)
        ->and(fn () => $board->move((string) $task->id, 'doing', null, 'dan'))->toThrow(MoveRejected::class) // doing accepts todo only
        ->and(fn () => laneBoard()->swimlanes('assignee')->columns([Column::make('done')->readOnly()])->move((string) $task->id, 'done', null, 'dan'))->toThrow(MoveRejected::class)
        ->and($task->fresh())->toMatchArray(['status' => 'done', 'assignee' => 'ana']);
});

it('passes the lane to moveUsing() as a fourth argument, only with swimlanes', function () {
    $task = task('Build', 'todo', ['assignee' => 'ana']);
    task('Other', 'doing', ['assignee' => 'dan']);
    $seen = [];

    $move = function (Task $record, string $to, string $from, ?string $lane = 'untouched') use (&$seen) {
        $seen[] = $lane;
        $record->update(['status' => $to, 'assignee' => $lane]);
    };

    laneBoard()->swimlanes('assignee')->moveUsing($move)->move((string) $task->id, 'doing', null, 'dan');
    laneBoard()->moveUsing($move)->move((string) $task->id, 'done');

    expect($seen)->toBe(['dan', 'untouched'])
        ->and($task->fresh()->status)->toBe('done');
});

it('answers lanes, counts and lane pages over Livewire', function () {
    task('Ana', 'todo', ['assignee' => 'ana', 'priority' => 2]);
    task('Dan', 'todo', ['assignee' => 'dan', 'priority' => 1]);

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->swimlanes('assignee')->perColumn(1);
        }
    };

    Livewire::test($component::class)
        ->call('kanbanRefresh')
        ->assertReturned(fn ($result) => array_column($result['lanes'], 'value') === ['ana', 'dan'] && $result['columns'][0]['counts'] === ['ana' => 1, 'dan' => 1])
        ->call('kanbanMore', 'todo', 0, '', [], 'dan')
        ->assertReturned(fn ($cards) => count($cards) === 1 && $cards[0]['lane'] === 'dan')
        ->call('kanbanMove', (string) Task::firstWhere('title', 'Ana')->id, 'doing', null, '', [], 'dan')
        ->assertReturned(fn ($result) => $result['ok'] && $result['card']['lane'] === 'dan');

    // Derived lanes follow the data: nobody is left in Ana's lane.
    expect(array_column(Livewire::test($component::class)->instance()->getKanbanConfig()['lanes'], 'value'))->toBe(['dan']);
});

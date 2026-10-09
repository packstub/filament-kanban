<?php

use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Events\CardMoved;
use Packstub\Kanban\Exceptions\MoveRejected;
use Packstub\Kanban\Lane;
use Packstub\Kanban\Tests\Fixtures\Priority;
use Packstub\Kanban\Tests\Fixtures\RankedTask;
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
        ['value' => 'ana', 'label' => 'Ana', 'color' => null, 'collapsed' => false, 'droppable' => true],
        ['value' => 'dan', 'label' => 'Dan', 'color' => null, 'collapsed' => false, 'droppable' => true],
        ['value' => '', 'label' => 'Unassigned', 'color' => null, 'collapsed' => false, 'droppable' => true],
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
            ['value' => '1', 'label' => 'Low priority', 'color' => 'gray', 'collapsed' => false, 'droppable' => true],
            ['value' => '9', 'label' => 'High priority', 'color' => 'red', 'collapsed' => false, 'droppable' => true],
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

it('shows one unassigned lane on an empty board with derived lanes, so there is somewhere to drop', function () {
    expect(array_map(fn (Lane $l) => $l->toArray(), laneBoard()->swimlanes('assignee')->getLanes()))->toBe([
        ['value' => '', 'label' => 'Unassigned', 'color' => null, 'collapsed' => false, 'droppable' => true],
    ]);
});

it('labels lanes derived from an enum-cast attribute with the enum', function () {
    task('High', 'todo', ['priority' => 9]);
    task('Low', 'todo', ['priority' => 1]);

    $board = laneBoard()->query(fn () => RankedTask::query())->card(fn (RankedTask $task) => Card::make()->title($task->title))->swimlanes('priority');

    expect(array_map(fn (Lane $l) => [$l->getValue(), $l->getLabel(), $l->getColor()], $board->getLanes()))->toBe([['9', 'High priority', 'red'], ['1', 'Low priority', 'gray']])
        ->and($board->getState()[0]['cards'][0])->toMatchArray(['lane' => '9', 'title' => 'High']);
});

it('caps derived lanes and folds the rest into an "Other" lane', function () {
    foreach (range(1, Board::MAX_DERIVED_LANES + 2) as $i) {
        task("Task {$i}", 'todo', ['assignee' => sprintf('user-%03d', $i), 'priority' => 1000 - $i]);
    }

    $board = laneBoard()->swimlanes('assignee');
    $lanes = $board->getLanes();

    expect($lanes)->toHaveCount(Board::MAX_DERIVED_LANES + 1)
        ->and($lanes[0]->getValue())->toBe('user-001')
        ->and(end($lanes)->toArray())->toMatchArray(['value' => '', 'label' => 'Other'])
        ->and($board->getState()[0]['counts'][''])->toBe(2)
        ->and(array_column($board->getCards('todo', lane: ''), 'title'))->toBe(['Task 51', 'Task 52']);
});

it('changes the lane only when the card lands in another row: a move inside its own row keeps whatever value it holds', function () {
    $zed = task('Zed', 'todo', ['assignee' => 'zed']); // not one of the defined lanes: shown in Unassigned
    $ana = task('Ana', 'todo', ['assignee' => 'ana']);
    $seen = [];

    $board = laneBoard()->swimlanes('assignee', [Lane::make('ana')])->moveUsing(function (Task $record, string $to, string $from, ?string $lane) use (&$seen) {
        $seen[] = $lane;
        $record->update(['status' => $to, ...($lane === null ? [] : ['assignee' => $lane === '' ? null : $lane])]);
    });

    $board->move((string) $zed->id, 'doing', null, '');          // dragged within the Unassigned row
    $board->move((string) $ana->id, 'doing', null, 'ana');       // dragged within its own row
    $board->move((string) $ana->id, 'done');                     // no lane sent (a bulk move, a "Move to…")

    expect($seen)->toBe([null, null, null])
        ->and($zed->fresh())->toMatchArray(['status' => 'doing', 'assignee' => 'zed'])
        ->and($ana->fresh())->toMatchArray(['status' => 'done', 'assignee' => 'ana']);

    $board->move((string) $zed->id, 'doing', null, 'ana');       // a real change writes
    $board->move((string) $ana->id, 'done', null, '');           // to Unassigned: moveUsing() sees '', the save writes null

    expect($seen)->toBe([null, null, null, 'ana', ''])
        ->and($zed->fresh()->assignee)->toBe('ana')
        ->and($ana->fresh()->assignee)->toBeNull();
});

it('keeps the lane on a bulk move and on the default save inside the row, and writes null for a drop into Unassigned', function () {
    Event::fake([CardMoved::class]);
    $zed = task('Zed', 'todo', ['assignee' => 'zed']);
    $ana = task('Ana', 'todo', ['assignee' => 'ana']);
    $board = laneBoard()->swimlanes('assignee', [Lane::make('ana')]);

    $board->move((string) $zed->id, 'doing', null, '');
    Event::assertDispatched(CardMoved::class, fn (CardMoved $e) => $e->record->is($zed) && $e->lane === null);
    $board->move((string) $ana->id, 'doing', null, '');
    Event::assertDispatched(CardMoved::class, fn (CardMoved $e) => $e->record->is($ana) && $e->lane === '');

    expect($zed->fresh())->toMatchArray(['status' => 'doing', 'assignee' => 'zed'])
        ->and($ana->fresh())->toMatchArray(['status' => 'doing', 'assignee' => null]);

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->swimlanes('assignee', [Lane::make('ana')]);
        }
    };

    $zed->update(['status' => 'todo']);
    Livewire::test($component::class)->call('kanbanMoveMany', [(string) $zed->id], 'doing')->assertReturned(fn ($r) => $r['ok']);

    expect($zed->fresh())->toMatchArray(['status' => 'doing', 'assignee' => 'zed']);
});

it('keeps a value past the derived-lane cap while the card moves inside "Other"', function () {
    foreach (range(1, Board::MAX_DERIVED_LANES + 1) as $i) {
        task("Task {$i}", 'todo', ['assignee' => sprintf('u%02d', $i), 'priority' => 1000 - $i]);
    }

    $last = Task::firstWhere('assignee', 'u51');
    laneBoard()->swimlanes('assignee')->move((string) $last->id, 'doing', null, '');

    expect($last->fresh())->toMatchArray(['status' => 'doing', 'assignee' => 'u51']);
});

it('reads a card\'s lane from the stored value, as the counts do, whatever the cast', function () {
    task('Calm', 'todo', ['priority' => 0]);
    task('Urgent', 'todo', ['priority' => 1]);
    $model = new class extends Task
    {
        protected $table = 'tasks';

        protected $casts = ['priority' => 'boolean'];
    };

    $state = laneBoard()->query(fn () => $model::query())->swimlanes('priority')->getState();

    expect($state[0]['counts'])->toEqual(['0' => 1, '1' => 1])
        ->and(array_map(fn ($c) => [$c['lane'], $c['title']], $state[0]['cards']))->toBe([['1', 'Urgent'], ['0', 'Calm']]);
});

it('loads a column\'s lanes in one query, limited per lane', function () {
    foreach (range(1, 3) as $i) {
        task("Ana {$i}", 'todo', ['assignee' => 'ana', 'priority' => $i + 3]);
        task("Dan {$i}", 'todo', ['assignee' => 'dan', 'priority' => $i]);
    }
    task('Loose', 'todo', ['priority' => 9]);

    $board = laneBoard()->swimlanes('assignee')->perColumn(2);

    DB::enableQueryLog();
    $cards = $board->getState()[0]['cards'];

    expect(array_map(fn ($c) => [$c['lane'], $c['title']], $cards))->toBe([['', 'Loose'], ['ana', 'Ana 3'], ['ana', 'Ana 2'], ['dan', 'Dan 3'], ['dan', 'Dan 2']])
        ->and(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'laravel_row')))->toHaveCount(1)
        ->and($cards[0])->not->toHaveKey('laravel_row');
});

it('saves the lane itself for a moveUsing() closure without a lane parameter', function () {
    $task = task('Build', 'todo', ['assignee' => 'ana']);
    task('Other', 'doing', ['assignee' => 'dan']);
    $calls = [];

    $board = laneBoard()->swimlanes('assignee')->moveUsing(function (Task $record, string $to, string $from) use (&$calls) {
        $calls[] = [$from, $to];
        $record->update(['status' => $to]);
    });

    $board->move((string) $task->id, 'todo', null, 'dan'); // a lane change inside the column: not the closure's business

    expect($calls)->toBe([])
        ->and($task->fresh()->only('status', 'assignee'))->toBe(['status' => 'todo', 'assignee' => 'dan']);

    $board->move((string) $task->id, 'doing', null, 'ana');

    expect($calls)->toBe([['todo', 'doing']])
        ->and($task->fresh()->only('status', 'assignee'))->toBe(['status' => 'doing', 'assignee' => 'ana']);
});

it('takes no drop into the "Other" lane, which would erase the card\'s value', function () {
    foreach (range(1, Board::MAX_DERIVED_LANES + 2) as $i) {
        task("Task {$i}", 'todo', ['assignee' => sprintf('user-%03d', $i), 'priority' => 1000 - $i]);
    }

    $board = laneBoard()->swimlanes('assignee');

    expect($board->getLane('')->toArray()['droppable'])->toBeFalse()
        ->and(fn () => $board->move('1', 'todo', null, ''))->toThrow(MoveRejected::class)
        ->and(Task::query()->find(1)->assignee)->toBe('user-001');
});

it('accepts a Filament palette as a lane colour', function () {
    expect(Lane::make('high')->color(fn () => Color::Red)->getColor())->toBeString();
});

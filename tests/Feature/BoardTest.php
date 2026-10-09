<?php

use Filament\Actions\CreateAction;
use Filament\Support\Colors\Color;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Events\BoardChanged;
use Packstub\Kanban\Events\CardMoved;
use Packstub\Kanban\Exceptions\MoveRejected;
use Packstub\Kanban\Filter;
use Packstub\Kanban\Tests\Fixtures\Status;
use Packstub\Kanban\Tests\Fixtures\Task;

function board(): Board
{
    return Board::make()
        ->query(fn () => Task::query()->with('project'))
        ->columns([
            Column::make('todo')->label('To do')->color('sky'),
            Column::make('doing')->accepts(['todo']),
            Column::make('done')->accepts(['doing'])->sortBy('id', 'desc'),
        ])
        ->card(fn (Task $task) => Card::make()->eyebrow('#'.$task->id)->title($task->title)->badge('urgent', 'red', $task->priority > 5)->meta([$task->project?->name, null]));
}

it('draws every visible column with its count and cards', function () {
    task('Write docs', 'todo', ['priority' => 9]);
    task('Ship it', 'doing');

    $state = board()->getState();

    expect(array_column($state, 'name'))->toBe(['todo', 'doing', 'done'])
        ->and($state[0])->toMatchArray(['label' => 'To do', 'color' => 'sky', 'count' => 1, 'accepts' => null, 'droppable' => true])
        ->and($state[1]['accepts'])->toBe(['todo'])
        ->and($state[0]['cards'][0])->toMatchArray(['eyebrow' => '#1', 'title' => 'Write docs', 'badges' => [['label' => 'urgent', 'color' => 'red']]])
        ->and($state[0]['cards'][0])->not->toHaveKey('meta') // empty parts are dropped
        ->and($state[2])->toMatchArray(['count' => 0, 'cards' => []]);
});

it('leaves hidden columns out entirely, cards and counts included', function () {
    task('Secret', 'done');

    $board = board()->columns([Column::make('todo'), Column::make('done')->visible(false)]);

    expect(array_column($board->getState(), 'name'))->toBe(['todo'])
        ->and($board->baseQuery()->count())->toBe(0);
});

it('sorts by the board default and lets a column override it', function () {
    task('low', 'todo', ['priority' => 1]);
    task('high', 'todo', ['priority' => 9]);
    task('first done', 'done');
    task('second done', 'done');

    $board = board()->sortBy('priority', 'desc');

    expect(array_column($board->getCards('todo'), 'title'))->toBe(['high', 'low'])
        ->and(array_column($board->getCards('done'), 'title'))->toBe(['second done', 'first done']);
});

it('pages each column', function () {
    foreach (range(1, 5) as $i) {
        task("Task {$i}");
    }

    $board = board()->perColumn(2);

    expect(array_column($board->getCards('todo'), 'title'))->toBe(['Task 1', 'Task 2'])
        ->and(array_column($board->getCards('todo', offset: 4), 'title'))->toBe(['Task 5'])
        ->and($board->getState()[0]['count'])->toBe(5);
});

it('keeps the pages already loaded on a refresh, up to ten pages', function () {
    foreach (range(1, 25) as $i) {
        task("Task {$i}");
    }

    $board = board()->perColumn(2);

    expect($board->getState(loaded: ['todo' => 4])[0]['cards'])->toHaveCount(4)
        ->and($board->getState(loaded: ['todo' => 1])[0]['cards'])->toHaveCount(2)
        ->and($board->getState(loaded: ['todo' => 500])[0]['cards'])->toHaveCount(20);
});

it('searches attributes and relationships, case-insensitively', function () {
    $acme = project('Acme Corp');
    task('Invoice run');
    task('Kickoff', 'todo', ['project_id' => $acme->id]);
    task('100% done?');

    $board = board()->searchable(['title', 'project.name']);

    expect(array_column($board->getCards('todo', 'INVOICE'), 'title'))->toBe(['Invoice run'])
        ->and(array_column($board->getCards('todo', 'acme'), 'title'))->toBe(['Kickoff'])
        ->and(array_column($board->getCards('todo', '100%'), 'title'))->toBe(['100% done?'])
        ->and(array_column($board->getCards('todo', '0%'), 'title'))->toBe(['100% done?'])
        ->and($board->getState('acme')[0]['count'])->toBe(1);
});

it('applies filters and ignores values that are not offered', function () {
    $acme = project('Acme');
    task('A', 'todo', ['project_id' => $acme->id]);
    task('B');

    $board = board()->filters([Filter::make('project_id')->options(fn () => [$acme->id => 'Acme'])]);

    expect(array_column($board->getCards('todo', filters: ['project_id' => $acme->id]), 'title'))->toBe(['A'])
        ->and(array_column($board->getCards('todo', filters: ['project_id' => 999]), 'title'))->toBe(['A', 'B'])
        ->and($board->getFilters()[0]->getLabel())->toBe('Project');
});

it('moves a card and returns it as it looks afterwards', function () {
    $task = task('Build');

    $card = board()->move((string) $task->id, 'doing');

    expect($task->fresh()->status)->toBe('doing')
        ->and($card)->toMatchArray(['id' => (string) $task->id, 'title' => 'Build']);
});

it('refuses moves the column rules do not allow, whatever the browser sent', function (Board $board, string $from, string $to) {
    $task = task('Build', $from);

    expect(fn () => $board->move((string) $task->id, $to))->toThrow(MoveRejected::class)
        ->and($task->fresh()->status)->toBe($from);
})->with([
    'not accepted from there' => fn () => [board(), 'todo', 'done'],
    'target not droppable' => fn () => [board()->columns([Column::make('todo'), Column::make('doing')->droppable(false)]), 'todo', 'doing'],
    'source not draggable' => fn () => [board()->columns([Column::make('todo')->draggable(false), Column::make('doing')]), 'todo', 'doing'],
    'read-only target' => fn () => [board()->columns([Column::make('todo'), Column::make('doing')->readOnly()]), 'todo', 'doing'],
    'hidden target' => fn () => [board()->columns([Column::make('todo'), Column::make('doing')->hidden()]), 'todo', 'doing'],
    'unknown target' => fn () => [board(), 'todo', 'archived'],
]);

it('refuses a card outside the query', function () {
    $task = task('Elsewhere');

    $board = board()->query(fn () => Task::query()->where('title', '!=', 'Elsewhere'));

    expect(fn () => $board->move((string) $task->id, 'doing'))->toThrow(MoveRejected::class, 'no longer on the board');
});

it('runs the app move: a MoveRejected refuses with its message, any other exception propagates as it is', function () {
    $task = task('Build');
    $other = task('Review');

    $board = board()->moveUsing(function (Task $record, string $to, string $from) {
        throw_if($record->title === 'Review', new MoveRejected('Needs a review first.'));
        throw_if($record->title === 'Build', new RuntimeException('A bug in the closure.'));
    });

    expect(fn () => $board->move((string) $other->id, 'doing'))->toThrow(MoveRejected::class, 'Needs a review first.')
        ->and(fn () => $board->move((string) $task->id, 'doing'))->toThrow(RuntimeException::class, 'A bug in the closure.')
        ->and($task->fresh()->status)->toBe('todo')
        ->and($other->fresh()->status)->toBe('todo');

    $moved = [];
    board()->moveUsing(function (Task $record, string $to, string $from) use (&$moved) {
        $moved = [$record->id, $from, $to];
        $record->update(['status' => $to, 'title' => 'Build (started)']);
    })->move((string) $task->id, 'doing');

    expect($moved)->toBe([$task->id, 'todo', 'doing'])
        ->and($task->fresh()->status)->toBe('doing');
});

it('stores the order of a reorderable column', function () {
    $a = task('A', 'todo', ['sort' => 1]);
    $b = task('B', 'todo', ['sort' => 2]);
    $c = task('C', 'doing');
    $other = task('Other column', 'doing');

    $board = board()->reorderable('sort');
    $board->move((string) $c->id, 'todo', [(string) $b->id, (string) $c->id, (string) $a->id, (string) $other->id]);

    expect(array_column($board->getCards('todo'), 'title'))->toBe(['B', 'C', 'A'])
        ->and($other->fresh()->sort)->toBeNull(); // ids from other columns are ignored
});

it('evaluates column rules as closures', function () {
    $column = Column::make('done')
        ->label(fn () => 'Shipped')
        ->visible(fn () => true)
        ->droppable(fn () => false)
        ->accepts(fn () => ['doing'])
        ->collapsed(fn () => true);

    expect($column->getLabel())->toBe('Shipped')
        ->and($column->isDroppable())->toBeFalse()
        ->and($column->accepting('doing'))->toBeTrue()
        ->and($column->accepting('todo'))->toBeFalse()
        ->and($column->isCollapsed())->toBeTrue()
        ->and(Column::make('in_review')->getLabel())->toBe('In Review');
});

it('builds columns from a backed enum, with its labels and colours', function () {
    task('Enum-backed', 'doing');

    $state = board()->columns(Status::class)->getState();

    expect(array_column($state, 'name'))->toBe(['todo', 'doing', 'done'])
        ->and(array_column($state, 'label'))->toBe(['To do', 'In progress', 'Done'])
        ->and($state[0]['color'])->toBe('gray')
        ->and($state[1]['color'])->toBe(Color::Amber[500])
        ->and($state[1]['count'])->toBe(1)
        ->and(Column::fromEnum(Status::class)[2]->accepts(['doing'])->accepting('todo'))->toBeFalse();
});

it('refuses a move into a column at its limit, counting cards the search hides', function () {
    task('Busy', 'doing');
    $task = task('Next');

    $board = board()->searchable(['title'])->columns([Column::make('todo'), Column::make('doing')->limit(1)]);

    expect($board->getState('next')[1])->toMatchArray(['limit' => 1, 'total' => 1, 'count' => 0])
        ->and(fn () => $board->move((string) $task->id, 'doing'))->toThrow(MoveRejected::class, 'Doing is full')
        ->and($task->fresh()->status)->toBe('todo');

    $board->columns([Column::make('todo'), Column::make('doing')->limit(2)])->move((string) $task->id, 'doing');

    expect($task->fresh()->status)->toBe('doing');
});

it('summarizes each column over the cards the search and filters leave', function () {
    task('Alpha', 'todo', ['priority' => 3]);
    task('Beta', 'todo', ['priority' => 4]);

    $board = board()->searchable(['title'])->summarize(fn (Builder $query, Column $column) => $column->getName().': '.$query->sum('priority'));

    expect($board->getState()[0]['summary'])->toBe('todo: 7')
        ->and($board->getState('beta')[0]['summary'])->toBe('todo: 4')
        ->and($board->getSummaries(['todo', 'nope', 'todo']))->toBe(['todo' => 'todo: 7'])
        ->and(board()->getState()[0]['summary'])->toBeNull();
});

it('knows where a new card may be created', function () {
    task('Busy', 'doing');

    $board = board()
        ->createAction(CreateAction::make())
        ->columns([Column::make('todo'), Column::make('doing')->limit(1), Column::make('done')->creatable(false), Column::make('archived')->readOnly()]);

    expect($board->canCreateIn('todo'))->toBeTrue()
        ->and($board->canCreateIn('doing'))->toBeFalse() // full
        ->and($board->canCreateIn('done'))->toBeFalse()
        ->and($board->canCreateIn('archived'))->toBeFalse()
        ->and($board->canCreateIn('missing'))->toBeFalse()
        ->and(array_column($board->getState(), 'creatable'))->toBe([true, true, false, false])
        ->and(board()->canCreateIn('todo'))->toBeFalse(); // no create action
});

it('dispatches CardMoved when a card changes column, not when it is reordered', function () {
    Event::fake([CardMoved::class]);
    $task = task('Build');

    $board = board()->reorderable('sort')->key('tasks');
    $board->move((string) $task->id, 'todo', [(string) $task->id]);
    Event::assertNotDispatched(CardMoved::class);

    $board->move((string) $task->id, 'doing');
    Event::assertDispatched(CardMoved::class, fn (CardMoved $e) => $e->record->is($task) && $e->from === 'todo' && $e->to === 'doing' && $e->board === 'tasks');
});

it('broadcasts on a private channel named after the key unless told otherwise, with Echo\'s event name', function () {
    expect(board()->isBroadcasting())->toBeFalse()
        ->and(board()->getBroadcastChannel())->toBeNull();

    $board = board()->key('App\\Filament\\Pages\\Deals')->broadcast();

    expect($board->getBroadcastChannel())->toBe('kanban.App.Filament.Pages.Deals')
        ->and($board->getBroadcastEvent())->toBe('.kanban.changed')
        ->and(board()->broadcast(fn () => 'tenant.7.kanban', '.deals')->getBroadcastChannel())->toBe('tenant.7.kanban');

    $event = new BoardChanged('tenant.7.kanban', '.kanban.changed', 'deals', '3', 'todo', 'doing', 'tab1');

    expect($event->broadcastOn())->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()->name)->toBe('private-tenant.7.kanban')
        ->and($event->broadcastAs())->toBe('kanban.changed')
        ->and($event->broadcastWith())->toBe(['board' => 'deals', 'id' => '3', 'from' => 'todo', 'to' => 'doing', 'origin' => 'tab1']);
});

it('reads the poll interval', function (string|int|null $interval, ?int $ms) {
    expect(board()->poll($interval)->getPoll())->toBe($ms);
})->with([
    ['10s', 10000], ['1m', 60000], ['2500ms', 2500], [5000, 5000], [10, 1000], [null, null],
]);

it('shapes avatars and the actions a card offers', function () {
    $card = Card::make()->title('A')->avatar('https://acme.test/a.png', 'Ana Pop')->avatar(null, 'Dan Ionescu')->avatar(null)->actions([]);

    expect($card->toArray())->toMatchArray([
        'avatars' => [['url' => 'https://acme.test/a.png', 'name' => 'Ana Pop'], ['url' => null, 'name' => 'Dan Ionescu']],
        'actions' => [],
    ])->and(Card::make()->title('B')->toArray())->not->toHaveKeys(['avatars', 'actions']);
});

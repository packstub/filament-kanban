<?php

use Filament\Actions\CreateAction;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Events\BoardChanged;
use Packstub\Kanban\Events\BoardChangedNow;
use Packstub\Kanban\Events\CardMoved;
use Packstub\Kanban\Exceptions\MoveRejected;
use Packstub\Kanban\Filter;
use Packstub\Kanban\Tests\Fixtures\Status;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;

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

it('ignores a list sent to a select filter', function () {
    $acme = project('Acme');
    task('A', 'todo', ['project_id' => $acme->id]);
    task('B');

    $board = board()->filters([Filter::make('project_id')->options(fn () => [$acme->id => 'Acme'])]);

    expect(array_column($board->getCards('todo', filters: ['project_id' => [$acme->id]]), 'title'))->toBe(['A', 'B']);
});

it('applies a multiple filter with whereIn and drops the values it does not offer one by one', function () {
    $acme = project('Acme');
    $globex = project('Globex');
    $initech = project('Initech');
    task('A', 'todo', ['project_id' => $acme->id]);
    task('B', 'todo', ['project_id' => $globex->id]);
    task('C', 'todo', ['project_id' => $initech->id]);
    task('D');

    $filter = Filter::make('project_id')->multiple()->options(fn () => [$acme->id => 'Acme', $globex->id => 'Globex']);
    $board = board()->filters([$filter]);

    expect($filter->getType())->toBe('multiple')
        ->and(array_column($board->getCards('todo', filters: ['project_id' => [(string) $acme->id, $globex->id]]), 'title'))->toBe(['A', 'B'])
        ->and(array_column($board->getCards('todo', filters: ['project_id' => [$acme->id, $initech->id, 999, '', ['nested']]]), 'title'))->toBe(['A'])
        ->and(array_column($board->getCards('todo', filters: ['project_id' => [$initech->id]]), 'title'))->toBe(['A', 'B', 'C', 'D'])
        ->and(array_column($board->getCards('todo', filters: ['project_id' => $acme->id]), 'title'))->toBe(['A']) // a single value is a list of one
        ->and(array_column($board->getCards('todo', filters: ['project_id' => []]), 'title'))->toBe(['A', 'B', 'C', 'D']);

    $received = null;
    $board->filters([Filter::make('project_id')->multiple()->options([$acme->id => 'Acme', $globex->id => 'Globex'])->query(function (Builder $query, array $values) use (&$received) {
        $received = $values;
        $query->whereIn('project_id', $values);
    })])->getCards('todo', filters: ['project_id' => [(string) $globex->id, 'nope', (string) $globex->id]]);

    expect($received)->toBe([(string) $globex->id]); // strings, as a select always passed them, without duplicates
});

it('applies a toggle filter only when it is on, through its required query', function () {
    task('Urgent', 'todo', ['priority' => 9]);
    task('Calm', 'todo', ['priority' => 1]);

    $filter = Filter::make('urgent')->toggle()->query(fn (Builder $query, bool $on) => $query->where('priority', '>', 5));
    $board = board()->filters([$filter]);

    expect($filter->getType())->toBe('toggle')
        ->and($filter->getOptions())->toBe([])
        ->and(array_column($board->getCards('todo', filters: ['urgent' => true]), 'title'))->toBe(['Urgent'])
        ->and(array_column($board->getCards('todo', filters: ['urgent' => '1']), 'title'))->toBe(['Urgent'])
        ->and(array_column($board->getCards('todo', filters: ['urgent' => false]), 'title'))->toBe(['Urgent', 'Calm'])
        ->and(array_column($board->getCards('todo', filters: ['urgent' => '0']), 'title'))->toBe(['Urgent', 'Calm'])
        ->and(array_column($board->getCards('todo', filters: ['urgent' => ['1']]), 'title'))->toBe(['Urgent', 'Calm'])
        ->and(array_column($board->getCards('todo'), 'title'))->toBe(['Urgent', 'Calm'])
        ->and(fn () => board()->filters([Filter::make('urgent')->toggle()])->getCards('todo', filters: ['urgent' => true]))->toThrow(LogicException::class, 'query()')
        ->and(fn () => Filter::make('urgent')->toggle()->assertUsable())->toThrow(LogicException::class)
        ->and(Filter::make('urgent')->toggle()->toggle(false)->getType())->toBe('select')
        ->and(Filter::make('owner_id')->multiple()->multiple(false)->getType())->toBe('select')
        ->and(Filter::make('owner_id')->multiple()->toggle(false)->getType())->toBe('multiple')
        ->and(Filter::make('urgent')->toggle()->multiple(false)->getType())->toBe('toggle');
});

it('resolves the options once per request, and not at all while the filter is not set', function () {
    $acme = project('Acme');
    task('A', 'todo', ['project_id' => $acme->id]);
    task('B');
    $calls = 0;

    $board = board()->summarize(fn (Builder $query) => $query->count())->filters([
        Filter::make('project_id')->multiple()->options(function () use (&$calls, $acme) {
            $calls++;

            return [$acme->id => 'Acme'];
        }),
        Filter::make('title')->options(function () use (&$calls) {
            $calls++;

            return ['A' => 'A'];
        }),
    ]);

    $board->getState();
    $board->getState(filters: ['project_id' => [], 'title' => '']);
    expect($calls)->toBe(0);

    $state = $board->getState(filters: ['project_id' => [(string) $acme->id], 'title' => 'A']);
    expect($calls)->toBe(2) // once each, over the counts, three columns of cards and three summaries
        ->and($state[0]['count'])->toBe(1);
});

it('keeps the search and filters in the URL unless told otherwise', function () {
    expect(board()->persistsInUrl())->toBeTrue()
        ->and(board()->persistInUrl(false)->persistsInUrl())->toBeFalse();
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

it('counts the WIP totals of every limited column in one grouped query', function () {
    task('A', 'todo');
    task('B', 'doing');
    task('C', 'doing');

    $limited = board()->columns([Column::make('todo')->limit(5), Column::make('doing')->limit(2), Column::make('done')]);

    DB::enableQueryLog();
    $state = $limited->getState();
    $grouped = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'count(*)'));

    expect(array_column($state, 'total'))->toBe([1, 2, null])
        ->and($grouped)->toHaveCount(2) // the filtered counts and the unfiltered totals
        ->and($grouped->every(fn (string $sql) => str_contains($sql, 'group by')))->toBeTrue();

    DB::flushQueryLog();
    board()->getState();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'count(*)')))->toHaveCount(1); // no limit, no totals
});

it('renumbers the whole column: the loaded cards first, the others after them in their current order', function () {
    $a = task('A', 'todo', ['sort' => 1]);
    $b = task('B', 'todo', ['sort' => 2]);
    $c = task('C', 'todo', ['sort' => 3]);
    $d = task('D', 'todo', ['sort' => 4]);
    $n = task('N', 'todo'); // no position yet

    $board = board()->reorderable('sort')->perColumn(2);

    DB::enableQueryLog();
    $board->move((string) $b->id, 'todo', [(string) $b->id, (string) $a->id]);
    $updates = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with($sql, 'update'));

    expect(array_column($board->getCards('todo', limit: 10), 'title'))->toBe(['B', 'A', 'C', 'D', 'N'])
        ->and(Task::query()->orderBy('sort')->pluck('sort', 'title')->all())->toBe(['B' => 1, 'A' => 2, 'C' => 3, 'D' => 4, 'N' => 5])
        ->and($updates)->toHaveCount(2); // only the positions that change, one statement each for the loaded (B, A) and the unnumbered (N); C and D already fit
});

it('shifts the cards beyond the loaded page in one statement when they collide, and leaves them alone when they do not', function () {
    foreach (range(1, 6) as $i) {
        task("T{$i}", 'todo', ['sort' => $i]);
    }
    $new = task('New', 'doing', ['sort' => 1]);

    $board = board()->reorderable('sort')->perColumn(2);

    // Dropped at the top of the column: the two loaded cards get 1 and 2, the four below (3..6) collide and move up by one.
    DB::enableQueryLog();
    $board->move((string) $new->id, 'todo', [(string) $new->id, '1', '2']);
    $updates = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with($sql, 'update'));

    expect(Task::query()->orderBy('sort')->pluck('sort', 'title')->all())->toBe(['New' => 1, 'T1' => 2, 'T2' => 3, 'T3' => 4, 'T4' => 5, 'T5' => 6, 'T6' => 7])
        ->and($updates)->toHaveCount(3) // the status, T1 and T2 together (New already is 1), then one shift of the tail
        ->and($updates->last())->toContain('"sort" = "tasks"."sort" + 1');

    // Reordered within the loaded page: the tail already sits above it, nothing below is touched.
    DB::flushQueryLog();
    $board->move('2', 'todo', ['2', (string) $new->id, '1']);

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with($sql, 'update')))->toHaveCount(1)
        ->and(Task::query()->orderBy('sort')->pluck('sort', 'title')->all())->toBe(['T2' => 1, 'New' => 2, 'T1' => 3, 'T3' => 4, 'T4' => 5, 'T5' => 6, 'T6' => 7]);
});

it('sorts a card without a position last, never first', function () {
    task('A', 'todo', ['sort' => 1]);
    task('N', 'todo');
    task('B', 'todo', ['sort' => 2]);

    expect(array_column(board()->reorderable('sort')->getCards('todo'), 'title'))->toBe(['A', 'B', 'N']);
});

it('saves a move and its order in one transaction: a refusal or a failure rolls both back', function () {
    Event::fake([CardMoved::class]);
    $task = task('Build');
    $other = task('Review', 'doing', ['sort' => 1]);

    $board = board()->reorderable('sort')->moveUsing(function (Task $record, string $to) {
        $record->update(['status' => $to]);

        throw new MoveRejected('Changed my mind.');
    });

    expect(fn () => $board->move((string) $task->id, 'doing', [(string) $task->id, (string) $other->id]))->toThrow(MoveRejected::class, 'Changed my mind.')
        ->and($task->fresh()->status)->toBe('todo')
        ->and($other->fresh()->sort)->toBe(1);
    Event::assertNotDispatched(CardMoved::class);

    // The move itself goes through, then storing the order fails: the move is undone too.
    $broken = board()->reorderable('no_such_column');

    expect(fn () => $broken->move((string) $task->id, 'doing', [(string) $task->id]))->toThrow(QueryException::class)
        ->and($task->fresh()->status)->toBe('todo');
    Event::assertNotDispatched(CardMoved::class);

    board()->reorderable('sort')->move((string) $task->id, 'doing', [(string) $task->id, (string) $other->id]);

    expect($task->fresh())->toMatchArray(['status' => 'doing', 'sort' => 1])
        ->and($other->fresh()->sort)->toBe(2);
    Event::assertDispatched(CardMoved::class, 1);
});

it('orders by a qualified position, so a joined query with the same column stays valid', function () {
    task('B', 'todo', ['sort' => 2]);
    task('A', 'todo', ['sort' => 1]);

    $board = board()->reorderable('sort')
        ->query(fn () => Task::query()->select('tasks.*')->leftJoin('tasks as parent', 'parent.id', '=', 'tasks.project_id'));

    expect(array_column($board->getCards('todo'), 'title'))->toBe(['A', 'B']);
});

it('numbers cards without a position in batches, not one statement per card', function () {
    foreach (range(1, 600) as $i) {
        task("T{$i}");
    }
    $new = task('New', 'doing');

    DB::enableQueryLog();
    board()->reorderable('sort')->move((string) $new->id, 'todo', [(string) $new->id]);
    $updates = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with($sql, 'update'));

    expect($updates)->toHaveCount(4) // the status, New, then the 600 unnumbered cards in two statements
        ->and(Task::query()->where('title', 'T1')->value('sort'))->toBe(2)
        ->and(Task::query()->where('title', 'T600')->value('sort'))->toBe(601)
        ->and(Task::query()->whereNull('sort')->count())->toBe(0);
});

it('forgets a cached card when its move rolls back', function () {
    $task = task('Build');

    $board = board()->moveUsing(function (Task $record, string $to) {
        $record->setAttribute('status', $to)->save();

        throw new MoveRejected('Not now.');
    });

    expect(fn () => $board->move((string) $task->id, 'doing'))->toThrow(MoveRejected::class)
        ->and($board->findRecord((string) $task->id)->status)->toBe('todo');
});

it('keeps a saved move when a CardMoved listener throws, and reports the listener', function () {
    $task = task('Build');
    Event::listen(CardMoved::class, fn () => throw new RuntimeException('Mail server down'));
    Exceptions::fake();

    $card = board()->move((string) $task->id, 'doing');

    expect($card['title'])->toBe('Build')
        ->and($task->fresh()->status)->toBe('doing');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Mail server down');
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

    expect($board->getBroadcastChannel())->toBe('kanban.app-filament-pages-deals') // no dots: a {board} route parameter stops at one
        ->and(board()->key('project.1.5')->broadcast()->getBroadcastChannel())->toBe('kanban.project-1-5') // replaced, not dropped:
        ->and(board()->key('project.15')->broadcast()->getBroadcastChannel())->toBe('kanban.project-15') // two boards, two channels
        ->and($board->getBroadcastEvent())->toBe('.kanban.changed')
        ->and($board->isBroadcastingNow())->toBeFalse()
        ->and(board()->broadcast(fn () => 'tenant.7.kanban', 'deals.changed')->getBroadcastChannel())->toBe('tenant.7.kanban')
        ->and(board()->broadcast(null, 'deals.changed')->getBroadcastEvent())->toBe('.deals.changed')
        ->and(board()->broadcastNow()->isBroadcastingNow())->toBeTrue();

    $event = new BoardChanged('tenant.7.kanban', '.kanban.changed', 'deals', '3', 'todo', 'doing', 'tab1');

    expect($event->broadcastOn())->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()->name)->toBe('private-tenant.7.kanban')
        ->and($event->broadcastAs())->toBe('kanban.changed')
        ->and($event->broadcastWith())->toBe(['board' => 'deals', 'id' => '3', 'from' => 'todo', 'to' => 'doing', 'origin' => 'tab1'])
        ->and(new BoardChangedNow('c', 'e'))->toBeInstanceOf(ShouldBroadcastNow::class);
});

function brokenBroadcaster(): void
{
    config()->set('queue.default', 'sync');
    config()->set('broadcasting.default', 'down');
    config()->set('broadcasting.connections.down', ['driver' => 'down']);
    app(BroadcastManager::class)->extend('down', fn () => new class implements Broadcaster
    {
        public function auth($request): void {}

        public function validAuthenticationResponse($request, $result): void {}

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new BroadcastException('Connection refused');
        }
    });
}

it('keeps a move saved when the broadcaster is down: the failure is reported, the move answers ok', function () {
    Exceptions::fake();
    brokenBroadcaster();
    $task = task('Build');

    $board = board()->broadcastNow('team.1.kanban');
    $board->broadcastChange((string) $task->id, 'todo', 'doing', 'tab1');

    Exceptions::assertReported(BroadcastException::class);

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->broadcastNow('team.1.kanban');
        }
    };

    Livewire::test($component::class)
        ->call('kanbanMove', (string) $task->id, 'doing', null, '', [], null, 'tab1')
        ->assertReturned(fn ($result) => $result['ok'] === true)
        ->callAction('bump', arguments: ['kanbanRecord' => (string) $task->id, 'kanbanOrigin' => 'tab1'])
        ->assertHasNoErrors()
        ->assertDispatched('packstub-kanban-refresh');

    expect($task->fresh()->status)->toBe('doing')
        ->and($task->fresh()->priority)->toBe(1);
    Exceptions::assertReportedCount(3);
});

it('broadcasts once the transaction commits, and a broadcaster down inside one is still only reported', function (bool $now) {
    Exceptions::fake();
    brokenBroadcaster();
    $task = task('Build');
    $board = board()->broadcast('team.1.kanban', now: $now);

    DB::transaction(function () use ($board, $task) {
        $board->move((string) $task->id, 'doing');
        $board->broadcastChange((string) $task->id, 'todo', 'doing', 'tab1');

        Exceptions::assertNothingReported(); // not before the commit
    });

    Exceptions::assertReported(BroadcastException::class);

    expect($task->fresh()->status)->toBe('doing');

    Exceptions::fake();
    DB::transaction(function () use ($board, $task) {
        $board->broadcastChange((string) $task->id, 'todo', 'doing', 'tab1');

        throw new RuntimeException('rolled back');
    });
})->with(['queued' => false, 'now' => true])->throws(RuntimeException::class, 'rolled back');

it('offers an undo for five seconds unless told otherwise', function () {
    expect(board()->getUndo())->toBe(5)
        ->and(board()->undo(false)->getUndo())->toBeNull()
        ->and(board()->undo(10)->getUndo())->toBe(10)
        ->and(board()->undo(0)->getUndo())->toBe(1)
        ->and(board()->undo(false)->undo()->getUndo())->toBe(5);
});

it('reads the poll interval', function (string|int|null $interval, ?int $ms) {
    expect(board()->poll($interval)->getPoll())->toBe($ms);
})->with([
    ['10s', 10000], ['1m', 60000], ['2500ms', 2500], [5000, 5000], [10, 1000], [null, null],
]);

it('sets the default density and refuses an unknown one', function () {
    expect(board()->getDensity())->toBe('comfortable')
        ->and(board()->density('compact')->getDensity())->toBe('compact')
        ->and(fn () => board()->density('cozy'))->toThrow(InvalidArgumentException::class);
});

it('shapes avatars and the actions a card offers', function () {
    $card = Card::make()->title('A')->avatar('https://acme.test/a.png', 'Ana Pop')->avatar(null, 'Dan Ionescu')->avatar(null)->actions([]);

    expect($card->toArray())->toMatchArray([
        'avatars' => [['url' => 'https://acme.test/a.png', 'name' => 'Ana Pop'], ['url' => null, 'name' => 'Dan Ionescu']],
        'actions' => [],
    ])->and(Card::make()->title('B')->toArray())->not->toHaveKeys(['avatars', 'actions']);
});

it('shapes a description, progress, a due date and badge icons, and leaves them out when unset', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-09'));

    $card = Card::make()
        ->title('A')
        ->description('Two boxes, leave them at the gate')
        ->progress(3, 5)
        ->due(new DateTimeImmutable('2026-10-07'))
        ->badge('call', 'sky', icon: 'heroicon-m-phone')
        ->badge('plain', 'gray');

    expect($card->toArray())->toMatchArray([
        'description' => 'Two boxes, leave them at the gate',
        'progress' => ['value' => 0.6, 'label' => '3/5'],
        'due' => ['date' => '2026-10-07', 'label' => 'Oct 7'],
    ])
        ->and($card->toArray()['badges'][0]['icon'])->toBe('heroicon-m-phone')
        ->and($card->toArray()['badges'][1])->not->toHaveKey('icon')
        ->and(Card::make()->badge('x', icon: Heroicon::Phone)->toArray()['badges'][0]['icon'])->toBe('heroicon-m-phone')
        ->and(Card::make()->progress(0.4)->toArray()['progress'])->toBe(['value' => 0.4, 'label' => '40%'])
        ->and(Card::make()->progress(50)->toArray()['progress'])->toBe(['value' => 1.0, 'label' => '100%']) // one number is a fraction, clamped
        ->and(Card::make()->progress(7, 5)->toArray()['progress'])->toBe(['value' => 1.0, 'label' => '7/5'])
        ->and(Card::make()->progress(2.5, 5)->toArray()['progress'])->toBe(['value' => 0.5, 'label' => '2.5/5'])
        ->and(Card::make()->title('B')->progress(3, null)->toArray())->not->toHaveKey('progress') // nothing to count: no bar
        ->and(Card::make()->title('B')->progress(1, 0)->toArray())->not->toHaveKey('progress')
        ->and(Card::make()->due(new DateTimeImmutable('2025-10-07'))->toArray()['due']['label'])->toBe('Oct 7, 2025') // another year says so
        ->and(Card::make()->due(new DateTimeImmutable('2026-10-07'), 'Tomorrow')->toArray()['due']['label'])->toBe('Tomorrow')
        ->and(Card::make()->due(new DateTimeImmutable('2026-10-07 23:30', new DateTimeZone('UTC')))->toArray()['due']['date'])->toBe('2026-10-07') // the day of the date given, in its own zone
        ->and(Card::make()->title('B')->progress(null)->due(null)->toArray())->not->toHaveKeys(['description', 'progress', 'due', 'draggable']);

    config(['app.date_format' => 'd.m.Y']);

    expect(Card::make()->due(new DateTimeImmutable('2026-10-07'))->toArray()['due']['label'])->toBe('07.10.2026');
});

it('sends each badge icon once per request, by name, instead of inside every card', function () {
    task('A', 'todo', ['priority' => 9]);
    task('B', 'todo', ['priority' => 9]);

    $board = board()->card(fn (Task $task) => Card::make()->title($task->title)->badge('call', 'sky', icon: 'heroicon-m-phone')->badge('mail', icon: 'heroicon-m-envelope'));

    expect($board->getIcons())->toBe([])
        ->and($board->getCards('todo')[1]['badges'][0]['icon'])->toBe('heroicon-m-phone')
        ->and(array_keys($board->getIcons()))->toBe(['heroicon-m-phone', 'heroicon-m-envelope'])
        ->and($board->getIcons()['heroicon-m-phone'])->toContain('<svg')
        ->and(json_encode($board->getCards('todo')))->not->toContain('<svg');
});

it('evaluates a label closure once per column', function () {
    $runs = 0;
    $column = Column::make('todo')->label(function () use (&$runs) {
        $runs++;

        return new HtmlString('<em>To do</em>');
    });

    $state = board()->columns([$column])->getState();

    expect($state[0])->toMatchArray(['label' => '<em>To do</em>', 'labelHtml' => true])
        ->and($runs)->toBe(1);
});

it('locks a card: said in the JSON, refused by the server for a move and a reorder alike', function () {
    $locked = task('Locked');
    $free = task('Free');

    $board = board()->reorderable('sort')->card(fn (Task $task) => Card::make()->title($task->title)->locked($task->title === 'Locked'));

    expect($board->getCards('todo')[0])->toMatchArray(['title' => 'Locked', 'draggable' => false])
        ->and($board->getCards('todo')[1])->not->toHaveKey('draggable')
        ->and(Card::make()->draggable(false)->isDraggable())->toBeFalse()
        ->and(Card::make()->locked(false)->isDraggable())->toBeTrue()
        ->and(fn () => $board->move((string) $locked->id, 'doing'))->toThrow(MoveRejected::class, 'This card cannot be moved.')
        ->and(fn () => $board->move((string) $locked->id, 'todo', [(string) $free->id, (string) $locked->id]))->toThrow(MoveRejected::class, 'This card cannot be moved.')
        ->and($locked->fresh()->status)->toBe('todo')
        ->and($locked->fresh()->sort)->toBeNull();

    $board->move((string) $free->id, 'doing');

    expect($free->fresh()->status)->toBe('doing');

    // Dropped back where it was on a board without reordering: nothing changes, nothing to refuse.
    expect(board()->card(fn (Task $task) => Card::make()->title($task->title)->locked())->move((string) $locked->id, 'todo'))
        ->toMatchArray(['title' => 'Locked', 'draggable' => false]);
});

it('keeps a column label closure: set again, it runs again', function () {
    $column = Column::make('todo')->label(fn () => 'First');

    expect($column->getLabel())->toBe('First')
        ->and($column->label(fn () => 'Second')->getLabel())->toBe('Second');
});

it('tells the browser whether a column label is HTML, and gives it the icon and description', function () {
    $state = board()->columns([
        Column::make('todo')->label(fn () => new HtmlString('<em>To do</em>'))->icon('heroicon-m-inbox')->description(fn () => 'New work lands here'),
        Column::make('doing')->label('<b>not html</b>'),
        Column::make('done'),
    ])->getState();

    expect($state[0])->toMatchArray(['label' => '<em>To do</em>', 'labelHtml' => true, 'description' => 'New work lands here'])
        ->and($state[0]['icon'])->toContain('<svg')
        ->and($state[1])->toMatchArray(['label' => '<b>not html</b>', 'labelHtml' => false, 'icon' => null, 'description' => null])
        ->and(Column::make('x')->label(new HtmlString('<i>x</i>'))->hasHtmlLabel())->toBeTrue();
});

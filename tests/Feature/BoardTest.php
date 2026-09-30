<?php

use Packstub\Kanban\Board;
use Packstub\Kanban\Card;
use Packstub\Kanban\Column;
use Packstub\Kanban\Exceptions\MoveRejected;
use Packstub\Kanban\Filter;
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

it('runs the app move and turns its exception into a refusal with the same message', function () {
    $task = task('Build');

    $board = board()->moveUsing(function (Task $record, string $to, string $from) {
        throw_if($to === 'doing' && $record->title === 'Build', new RuntimeException('Needs a review first.'));
    });

    expect(fn () => $board->move((string) $task->id, 'doing'))->toThrow(MoveRejected::class, 'Needs a review first.');

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

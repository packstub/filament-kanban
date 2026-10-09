<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Packstub\Kanban\Board;
use Packstub\Kanban\Events\BoardChanged;
use Packstub\Kanban\Events\CardMoved;
use Packstub\Kanban\Exceptions\MoveRejected;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;

beforeEach(function () {
    TaskBoard::$visible = null;
    TaskBoard::$canShip = true;
    TaskBoard::$doingLimit = null;
});

it('renders the board with its state as JSON for the browser', function () {
    task('Write docs');

    Livewire::test(TaskBoard::class)
        ->assertSeeHtml('x-data="packstubKanban(')
        ->assertSeeHtml('packstub/filament-kanban/components/kanban.js')
        ->assertSee('Write docs')
        ->assertSeeHtml('wire:ignore')
        ->assertSeeHtml('<style>.pk { visibility: hidden; animation: pk-reveal 0s 1.5s forwards; }'); // revealed after 1.5 s should the stylesheet never arrive
});

it('sends the state with a board first drawn on a later request, and lets a redrawn board ask for it', function () {
    task('Write docs');

    $component = new class extends TaskBoard
    {
        public bool $ready = false;

        public function load(): void
        {
            $this->ready = true;
        }

        public function unload(): void
        {
            $this->ready = false;
        }

        public function render(): string
        {
            return '<div>@if ($ready) @include(\'packstub-kanban::board\') @else <p>loading</p> @endif <x-filament-actions::modals /></div>';
        }
    };

    Livewire::test($component::class)
        ->assertSee('loading')
        ->call('load') // deferred (wire:init, #[Lazy]): drawn with its state, no second request
        ->assertSeeHtml('x-data="packstubKanban(')
        ->assertSee('Write docs')
        ->call('unload')
        ->call('load') // drawn again: the browser calls kanbanRefresh() once and takes the columns whole
        ->assertSeeHtml('\u0022columns\u0022:null')
        ->call('kanbanRefresh')
        ->assertReturned(fn ($result) => $result['columns'][0]['cards'][0]['title'] === 'Write docs');
});

it('loads the board state on the first render only, never on a re-render', function () {
    $task = task('Write docs');
    TaskBoard::$doingLimit = 3;

    $component = Livewire::test(TaskBoard::class)
        ->assertSee('Write docs');

    DB::enableQueryLog();

    // A re-render of the page, then a card action's modal: the board is wire:ignored, so
    // neither may read a card, a count or a summary again.
    $component
        ->call('$refresh')
        ->assertSeeHtml('x-data="packstubKanban(')
        ->assertSeeHtml('\u0022columns\u0022:null')
        ->assertDontSee('Write docs')
        ->mountAction('edit', ['kanbanRecord' => (string) $task->id])
        ->assertActionMounted('edit');

    $reads = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'from "tasks"'));

    expect($reads->all())->toHaveCount(1) // the action's record
        ->and($reads->first())->toContain('"tasks"."id" = ?')
        ->and($reads->first())->not->toContain('count(*)');
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

it('keeps an exception thrown while saving out of the notification: reported, answered with a generic message', function () {
    Exceptions::fake();
    $task = task('Build');
    $other = task('Review');

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->moveUsing(function (Task $task, string $to) {
                throw_if($task->title === 'Review', new MoveRejected('Needs a review first.'));

                // What a database refusal (a check constraint, a strict-mode value) looks like.
                throw new QueryException('sqlite', 'update "tasks" set "status" = ? where "id" = ?', [$to, $task->id], new RuntimeException('constraint failed'));
            });
        }
    };

    Livewire::test($component::class)
        ->call('kanbanMove', (string) $task->id, 'doing')
        ->assertReturned(['ok' => false, 'message' => 'The move did not go through.'])
        ->call('kanbanMove', (string) $other->id, 'doing')
        ->assertReturned(['ok' => false, 'message' => 'Needs a review first.']);

    Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'update "tasks"'));
    Exceptions::assertReportedCount(1);

    expect($task->fresh()->status)->toBe('todo')
        ->and($other->fresh()->status)->toBe('todo');
});

it('tells the other tabs about a move only when the board broadcasts, with the tab\'s own token', function () {
    Event::fake([BoardChanged::class]);
    $task = task('Build');

    Livewire::test(TaskBoard::class)
        ->call('kanbanMove', (string) $task->id, 'doing', null, '', [], null, 'tab1')
        ->assertReturned(fn ($result) => $result['ok'] === true);

    Event::assertNotDispatched(BoardChanged::class);

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->key('tasks')->broadcast(fn () => 'team.1.kanban');
        }
    };

    $test = Livewire::test($component::class);

    expect($test->instance()->getKanbanConfig()['broadcast'])->toBe(['channel' => 'team.1.kanban', 'event' => '.kanban.changed', 'board' => 'tasks']);

    $test->call('kanbanMove', (string) $task->id, 'done', null, '', [], null, 'tab1');

    Event::assertDispatched(BoardChanged::class, fn (BoardChanged $e) => $e->channel === 'team.1.kanban'
        && $e->broadcastWith() === ['board' => 'tasks', 'id' => (string) $task->id, 'from' => 'doing', 'to' => 'done', 'origin' => 'tab1']);

    $test->call('kanbanMove', (string) $task->id, 'doing') // refused: doing accepts cards from todo only
        ->assertReturned(fn ($result) => $result['ok'] === false)
        ->call('kanbanMove', (string) $task->id, 'done') // dropped back where it was, nothing reordered: nothing to tell
        ->assertReturned(fn ($result) => $result['ok'] === true);

    Event::assertDispatchedTimes(BoardChanged::class, 1);
});

it('undoes a move as a move back, through the rules and the events again', function () {
    Event::fake([CardMoved::class]);
    $task = task('Build');

    $test = Livewire::test(TaskBoard::class);

    expect($test->instance()->getKanbanConfig()['undo'])->toBe(5);

    $test->call('kanbanMove', (string) $task->id, 'doing')
        ->assertReturned(fn ($result) => $result['ok'] === true)
        ->call('kanbanMove', (string) $task->id, 'todo')
        ->assertReturned(fn ($result) => $result['ok'] === true);

    expect($task->fresh()->status)->toBe('todo');
    Event::assertDispatched(CardMoved::class, fn (CardMoved $e) => $e->from === 'todo' && $e->to === 'doing');
    Event::assertDispatched(CardMoved::class, fn (CardMoved $e) => $e->from === 'doing' && $e->to === 'todo');
    Event::assertDispatchedTimes(CardMoved::class, 2);
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
        ->assertReturned(fn ($result) => count($result['cards']) === 1 && $result['cards'][0]['title'] === 'Beta' && $result['icons'] === []);
});

it('refreshes as many cards as the browser already shows', function () {
    foreach (range(1, 3) as $i) {
        task("Task {$i}");
    }

    Livewire::test(TaskBoard::class)
        ->call('kanbanRefresh', '', [], ['todo' => 3])
        ->assertReturned(fn ($result) => count($result['columns'][0]['cards']) === 3);
});

it('tells the other tabs once about a bulk move, with the tab\'s token', function () {
    Event::fake([BoardChanged::class]);
    $a = task('A');
    $b = task('B');

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->broadcast();
        }
    };

    Livewire::test($component::class)
        ->call('kanbanMoveMany', [(string) $a->id, (string) $b->id], 'doing', '', [], null, 'tab1')
        ->assertReturned(fn ($result) => count($result['moved']) === 2);

    Event::assertDispatchedTimes(BoardChanged::class, 1);
    Event::assertDispatched(BoardChanged::class, fn (BoardChanged $e) => $e->origin === 'tab1');
});

it('gives a board drawn again its column actions with the columns, and only then', function () {
    $test = Livewire::test(TaskBoard::class);

    $plain = $test->instance()->kanbanRefresh();
    $whole = $test->instance()->kanbanRefresh(whole: true);

    expect($plain['columns'][0])->not->toHaveKey('actions')
        ->and(array_column(collect($whole['columns'])->firstWhere('name', 'done')['actions'], 'name'))->toBe(['column:done:archive']);

    $test->call('$refresh')->assertSeeHtml('\u0022columns\u0022:null'); // a re-render evaluates no column action
});

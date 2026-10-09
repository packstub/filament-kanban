<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Packstub\Kanban\Board;
use Packstub\Kanban\Exceptions\MoveRejected;
use Packstub\Kanban\Filter;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;

beforeEach(function () {
    TaskBoard::$visible = null;
    TaskBoard::$canShip = true;
});

it('renders the board with its state as JSON for the browser', function () {
    task('Write docs');

    Livewire::test(TaskBoard::class)
        ->assertSeeHtml('x-data="packstubKanban(')
        ->assertSeeHtml('packstub/filament-kanban/components/kanban.js')
        ->assertSee('Write docs')
        ->assertSeeHtml('wire:ignore')
        ->assertSeeHtml('<style>.pk { visibility: hidden; }</style>');
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
        ->assertReturned(fn ($cards) => count($cards) === 1 && $cards[0]['title'] === 'Beta');
});

it('refreshes with a multiple filter and a toggle, ignoring values that are not offered', function () {
    $acme = project('Acme');
    $globex = project('Globex');
    task('Alpha', 'todo', ['project_id' => $acme->id, 'priority' => 9]);
    task('Beta', 'todo', ['project_id' => $globex->id]);
    task('Gamma');

    Livewire::test(TaskBoard::class)
        ->call('kanbanRefresh', '', ['project_id' => [(string) $acme->id, (string) $globex->id, '999']])
        ->assertReturned(fn ($result) => $result['columns'][0]['count'] === 2 && array_column($result['columns'][0]['cards'], 'title') === ['Alpha', 'Beta'])
        ->call('kanbanRefresh', '', ['project_id' => ['999'], 'urgent' => '1'])
        ->assertReturned(fn ($result) => $result['columns'][0]['count'] === 1 && $result['columns'][0]['cards'][0]['title'] === 'Alpha')
        ->call('kanbanRefresh', '', ['project_id' => [], 'urgent' => false, 'unknown' => 'x'])
        ->assertReturned(fn ($result) => $result['columns'][0]['count'] === 3);
});

it('tells the browser each filter\'s type and whether to keep the state in the URL', function () {
    $acme = project('Acme');

    $config = Livewire::test(TaskBoard::class)->instance()->getKanbanConfig();

    expect($config['url'])->toBeTrue()
        ->and($config['filters'])->toBe([
            ['name' => 'project_id', 'label' => 'Project', 'type' => 'multiple', 'options' => [['value' => (string) $acme->id, 'label' => 'Acme']]],
            ['name' => 'urgent', 'label' => 'Urgent', 'type' => 'toggle', 'options' => []],
        ]);

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->persistInUrl(false);
        }
    };

    expect(Livewire::test($component::class)->instance()->getKanbanConfig()['url'])->toBeFalse();
});

it('draws a shared link filtered from the start, with what the URL says checked like any input', function () {
    $acme = project('Acme');
    task('Alpha', 'todo', ['project_id' => $acme->id, 'priority' => 9]);
    task('Alps', 'todo', ['project_id' => $acme->id]);
    task('Beta', 'todo', ['priority' => 9]);

    Livewire::withQueryParams(['search' => ' Al ', 'filters' => ['project_id' => [(string) $acme->id, '999'], 'urgent' => '1', 'bogus' => 'x']])
        ->test(TaskBoard::class)
        ->assertSee('Alpha')
        ->assertDontSee('Alps')
        ->assertDontSee('Beta')
        ->assertSeeHtml('\u0022initial\u0022:{\u0022search\u0022:\u0022Al\u0022')
        ->call('$refresh')
        ->assertSeeHtml('\u0022initial\u0022:null'); // a Livewire update reads no URL: the browser keeps its own state

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->persistInUrl(false);
        }
    };

    Livewire::withQueryParams(['filters' => ['urgent' => '1']])->test($component::class)->assertSee('Alps');
});

it('turns a URL value into what the browser holds', function () {
    $select = Filter::make('status')->options(['open' => 'Open', 3 => 'Three']);
    $multiple = Filter::make('owner')->multiple()->options([1 => 'Ana', 2 => 'Dan']);
    $toggle = Filter::make('mine')->toggle()->query(fn ($q) => $q);

    expect($select->state('open'))->toBe('open')
        ->and($select->state('3'))->toBe('3')
        ->and($select->state('nope'))->toBe('')
        ->and($select->state(['open']))->toBe('')
        ->and($multiple->state(['2', '1', '2', '9', ['x']]))->toBe(['1', '2'])
        ->and($multiple->state('1'))->toBe(['1'])
        ->and($toggle->state('1'))->toBeTrue()
        ->and($toggle->state('0'))->toBeFalse()
        ->and($toggle->state(null))->toBeFalse();
});

it('refuses to render a toggle filter without a query, before anyone clicks it', function () {
    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->filters([Filter::make('mine')->toggle()]);
        }
    };

    // The config is built inside the view, so the exception arrives wrapped by Blade (once per nested view).
    try {
        Livewire::test($component::class);
        $this->fail('The page rendered a toggle without a query.');
    } catch (ViewException $e) {
        while ($e instanceof ViewException && $e->getPrevious()) {
            $e = $e->getPrevious();
        }

        expect($e)->toBeInstanceOf(LogicException::class)
            ->and($e->getMessage())->toContain('Kanban filter [mine] is a toggle');
    }
});

it('refreshes as many cards as the browser already shows', function () {
    foreach (range(1, 3) as $i) {
        task("Task {$i}");
    }

    Livewire::test(TaskBoard::class)
        ->call('kanbanRefresh', '', [], ['todo' => 3])
        ->assertReturned(fn ($result) => count($result['columns'][0]['cards']) === 3);
});

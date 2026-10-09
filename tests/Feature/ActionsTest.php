<?php

use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Packstub\Kanban\Actions\ColumnAction;
use Packstub\Kanban\Board;
use Packstub\Kanban\Column;
use Packstub\Kanban\Events\BoardChanged;
use Packstub\Kanban\Tests\Fixtures\ArchiveColumnAction;
use Packstub\Kanban\Tests\Fixtures\PlainBoard;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;

beforeEach(function () {
    TaskBoard::$visible = null;
    TaskBoard::$canShip = true;
    TaskBoard::$doingLimit = null;
});

it('offers the card actions, the click action and the create action to the browser', function () {
    $config = Livewire::test(TaskBoard::class)->instance()->getKanbanConfig();

    expect(array_column($config['cardActions'], 'name'))->toBe(['edit', 'delete', 'bump'])
        ->and($config['cardActions'][1]['color'])->toBe('danger')
        ->and($config['cardAction'])->toBe('edit')
        ->and($config['createAction']['name'])->toBe('create');
});

it('offers no actions on a component without Filament\'s action system', function () {
    $config = Livewire::test(PlainBoard::class)->instance()->getKanbanConfig();

    expect($config['cardActions'])->toBe([])
        ->and($config['createAction'])->toBeNull()
        ->and($config['columns'][0]['actions'])->toBe([]);
});

it('offers each column its actions, named after the column, with the column injected', function () {
    $config = Livewire::test(TaskBoard::class)->instance()->getKanbanConfig();

    expect($config['columns'][0]['actions'][0])->toMatchArray(['name' => 'column:todo:archive', 'label' => 'Archive Todo'])
        ->and($config['columns'][2]['actions'][0])->toMatchArray(['name' => 'column:done:archive', 'label' => 'Archive Done']);
});

it('copies an action object shared by several columns, and leaves out the ones the app hides', function () {
    $component = new class extends TaskBoard
    {
        public static ?ColumnAction $shared = null;

        public function kanban(Board $board): Board
        {
            // Built per request, as an app's kanban() does; kept in a static for the assertions below.
            self::$shared = ColumnAction::make('clear')->visible(fn (Column $column) => $column->getName() !== 'doing')->action(fn (Builder $query) => $query->delete());

            return parent::kanban($board)->columns([
                Column::make('todo')->actions([self::$shared]),
                Column::make('doing')->actions([self::$shared, ColumnAction::make('secret')->authorize(false)]),
            ]);
        }
    };
    $todo = task('A', 'todo');
    $doing = task('B', 'doing');

    $test = Livewire::test($component::class);
    $config = $test->instance()->getKanbanConfig();
    $columns = $test->instance()->getKanban()->getAllColumns();

    expect(array_column($config['columns'][0]['actions'], 'name'))->toBe(['column:todo:clear'])
        ->and($config['columns'][1]['actions'])->toBe([])
        ->and($columns[0]->getActions()[0])->toBe($component::$shared) // the first column keeps the object
        ->and($columns[1]->getActions()[0])->not->toBe($component::$shared) // the second gets a copy
        ->and($columns[1]->getActions()[0]->getName())->toBe('column:doing:clear');

    $test->callAction('column:todo:clear', arguments: ['kanbanColumn' => 'todo'])
        ->assertActionHidden('column:doing:clear', ['kanbanColumn' => 'doing']);

    expect(Task::find($todo->id))->toBeNull()
        ->and($doing->fresh())->not->toBeNull();
});

it('keeps an action of its own as it is, so closures bound in setUp() through $this still work', function () {
    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->columns([
                Column::make('todo')->actions([ArchiveColumnAction::make('archive')]),
                Column::make('done')->actions([ArchiveColumnAction::make('archive')->databaseTransaction()]),
            ]);
        }
    };
    $todo = task('A', 'todo');
    $done = task('B', 'done');

    Livewire::test($component::class)
        ->callAction('column:todo:archive', arguments: ['kanbanColumn' => 'todo'])
        ->assertHasNoErrors()
        ->callAction('column:done:archive', arguments: ['kanbanColumn' => 'done'])
        ->assertHasNoErrors();

    expect($todo->fresh()->status)->toBe('archived')
        ->and($done->fresh()->status)->toBe('archived');
});

it('copies an object that is already a card action before making it a column action', function () {
    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            $touch = Action::make('touch')->action(fn () => null);

            return parent::kanban($board)
                ->cardActions([$touch])
                ->columns([Column::make('todo')->actions([$touch]), Column::make('doing')]);
        }
    };
    $task = task('A');

    $test = Livewire::test($component::class);
    $config = $test->instance()->getKanbanConfig();

    expect(array_column($config['cardActions'], 'name'))->toBe(['touch'])
        ->and(array_column($config['columns'][0]['actions'], 'name'))->toBe(['column:todo:touch']);

    $test->callAction('touch', arguments: ['kanbanRecord' => (string) $task->id])
        ->assertHasNoErrors()
        ->callAction('column:todo:touch', arguments: ['kanbanColumn' => 'todo'])
        ->assertHasNoErrors();
});

it('refuses to run a shared $this-based column action on another column\'s cards', function () {
    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            $archive = ArchiveColumnAction::make('archive');

            return parent::kanban($board)->columns([
                Column::make('todo')->actions([$archive]),
                Column::make('done')->actions([$archive]),
            ]);
        }
    };
    $todo = task('A', 'todo');
    $done = task('B', 'done');

    expect(fn () => Livewire::test($component::class)->callAction('column:done:archive', arguments: ['kanbanColumn' => 'done']))
        ->toThrow(LogicException::class, 'shared across columns')
        ->and($todo->fresh()->status)->toBe('todo')
        ->and($done->fresh()->status)->toBe('done');
});

it('re-renders with one column\'s action open while another column\'s own action reads $query', function () {
    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            $archive = fn () => ColumnAction::make('archive')
                ->requiresConfirmation()
                ->visible(fn (Builder $query) => $query->exists())
                ->action(fn (Builder $query) => $query->update(['status' => 'archived']));

            return parent::kanban($board)->columns([
                Column::make('todo')->actions([$archive()]),
                Column::make('done')->actions([$archive()]),
            ]);
        }
    };
    task('A', 'todo');
    $done = task('B', 'done');

    Livewire::test($component::class)
        ->mountAction('column:todo:archive', ['kanbanColumn' => 'todo'])
        ->call('$refresh') // a full render with the modal open: the done column's action is evaluated too
        ->assertActionMounted('column:todo:archive')
        ->callMountedAction()
        ->assertHasNoErrors();

    expect(Task::where('status', 'archived')->count())->toBe(1)
        ->and($done->fresh()->status)->toBe('done');
});

it('says what is missing when a column action is used off a column', function () {
    expect(fn () => ColumnAction::make('loose')->getColumnQuery())->toThrow(LogicException::class, 'has no column');
});

it('shrugs off arguments of the wrong type', function () {
    $task = task('Alpha', 'done');
    $other = task('Beta');

    Livewire::test(TaskBoard::class)
        ->callAction('column:done:archive', arguments: ['kanbanColumn' => 'done', 'kanbanSearch' => ['x'], 'kanbanFilters' => 'nope', 'kanbanOrigin' => ['t']])
        ->assertHasNoErrors()
        ->callAction('bump', arguments: ['kanbanRecord' => (string) $other->id, 'kanbanOrigin' => ['t']])
        ->assertHasNoErrors();

    expect($task->fresh()->status)->toBe('archived')
        ->and($other->fresh()->priority)->toBe(1);
});

it('runs a column action on the column\'s cards, as the search and filters leave them', function () {
    $acme = project('Acme');
    $alpha = task('Alpha', 'done', ['project_id' => $acme->id]);
    $beta = task('Beta', 'done');
    $gamma = task('Gamma', 'done', ['project_id' => $acme->id]);
    $todo = task('Alpha too', 'todo');

    Livewire::test(TaskBoard::class)
        ->callAction('column:done:archive', arguments: ['kanbanColumn' => 'done', 'kanbanSearch' => 'alp', 'kanbanFilters' => ['project_id' => $acme->id]])
        ->assertHasNoErrors()
        ->assertDispatched('packstub-kanban-refresh');

    expect($alpha->fresh()->status)->toBe('archived')
        ->and($beta->fresh()->status)->toBe('done')
        ->and($gamma->fresh()->status)->toBe('done')
        ->and($todo->fresh()->status)->toBe('todo');

    Livewire::test(TaskBoard::class)
        ->callAction('column:done:archive', arguments: ['kanbanColumn' => 'done', 'kanbanFilters' => ['project_id' => 999]]); // not an offered value: ignored

    expect(Task::where('status', 'done')->count())->toBe(0)
        ->and($todo->fresh()->status)->toBe('todo');
});

it('refuses a column action on a hidden column, or named for another column', function () {
    $done = task('Shipped', 'done');
    TaskBoard::$visible = ['todo', 'doing'];

    Livewire::test(TaskBoard::class)
        ->assertActionHidden('column:done:archive', ['kanbanColumn' => 'done'])
        ->assertActionHidden('column:todo:archive', ['kanbanColumn' => 'done'])
        ->call('mountAction', 'column:done:archive', ['kanbanColumn' => 'done']) // what a crafted request would send
        ->call('callMountedAction')
        ->assertNotDispatched('packstub-kanban-refresh');

    expect($done->fresh()->status)->toBe('done');
});

it('edits a card in a modal and tells the board to refresh', function () {
    $task = task('Draft');

    Livewire::test(TaskBoard::class)
        ->mountAction('edit', ['kanbanRecord' => (string) $task->id])
        ->assertSet('mountedActions.0.data.title', 'Draft')
        ->set('mountedActions.0.data.title', 'Final')
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertDispatched('packstub-kanban-refresh');

    expect($task->fresh()->title)->toBe('Final');
});

it('runs plain card actions on the card record, with the app\'s own visibility rules', function () {
    $task = task('Low', 'todo', ['priority' => 1]);
    $pinned = task('High', 'todo', ['priority' => 9]);

    Livewire::test(TaskBoard::class)
        ->callAction('bump', arguments: ['kanbanRecord' => (string) $task->id])
        ->assertActionHidden('delete', ['kanbanRecord' => (string) $pinned->id])
        ->callAction('delete', arguments: ['kanbanRecord' => (string) $task->id]);

    expect(Task::find($task->id))->toBeNull()
        ->and($pinned->fresh())->not->toBeNull();
});

it('never runs a card action on a record outside the board', function () {
    $hidden = task('Hidden', 'done');
    TaskBoard::$visible = ['todo', 'doing'];

    Livewire::test(TaskBoard::class)
        ->assertActionHidden('bump', ['kanbanRecord' => (string) $hidden->id])
        ->call('mountAction', 'bump', ['kanbanRecord' => (string) $hidden->id]) // what a crafted request would send
        ->call('callMountedAction')
        ->assertNotDispatched('packstub-kanban-refresh');

    expect($hidden->fresh()->priority)->toBe(0);
});

it('tells the other tabs after an action ran, when the board broadcasts', function () {
    Event::fake([BoardChanged::class]);
    $task = task('Low');

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->broadcast('team.1.kanban');
        }
    };

    Livewire::test($component::class)
        ->callAction('bump', arguments: ['kanbanRecord' => (string) $task->id, 'kanbanOrigin' => 'tab1'])
        ->assertDispatched('packstub-kanban-refresh');

    Event::assertDispatched(BoardChanged::class, fn (BoardChanged $e) => $e->id === (string) $task->id && $e->origin === 'tab1' && $e->from === null);
});

it('creates a card in the column it was asked from', function () {
    Livewire::test(TaskBoard::class)
        ->callAction('create', data: ['title' => 'Fresh'], arguments: ['kanbanColumn' => 'doing'])
        ->assertHasNoErrors()
        ->assertDispatched('packstub-kanban-refresh');

    expect(Task::firstWhere('title', 'Fresh')?->status)->toBe('doing');
});

it('refuses to create in a column that is not creatable, full or unknown', function (string $column) {
    task('Busy', 'doing');
    TaskBoard::$doingLimit = 1;
    TaskBoard::$visible = ['todo', 'doing', 'done'];

    Livewire::test(TaskBoard::class)
        ->assertActionHidden('create', ['kanbanColumn' => $column])
        ->call('mountAction', 'create', ['kanbanColumn' => $column])
        ->set('mountedActions.0.data.title', 'Sneaky')
        ->call('callMountedAction');

    expect(Task::where('title', 'Sneaky')->exists())->toBeFalse();
})->with(['not creatable' => 'done', 'full' => 'doing', 'unknown' => 'archived']);

it('answers a move with the summaries of both columns', function () {
    $task = task('Build', 'todo', ['priority' => 3]);
    task('Other', 'todo', ['priority' => 2]);

    Livewire::test(TaskBoard::class)
        ->call('kanbanMove', (string) $task->id, 'doing')
        ->assertReturned(fn ($result) => $result['summaries'] === ['todo' => '2 pts', 'doing' => '3 pts']);
});

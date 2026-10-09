<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Packstub\Kanban\Actions\KanbanAction;
use Packstub\Kanban\Actions\TableAction;
use Packstub\Kanban\Board;
use Packstub\Kanban\Column;
use Packstub\Kanban\Pages\KanbanResourcePage;
use Packstub\Kanban\Tests\Fixtures\ListTasks;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoardPage;
use Packstub\Kanban\Tests\Fixtures\TaskResource;

beforeEach(function () {
    TaskBoardPage::$query = null;
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('shows the resource\'s records when the page sets no query', function () {
    $project = project('Acme');
    task('Mine');
    task('Theirs', attributes: ['project_id' => $project->id]);

    $board = app(TaskBoardPage::class)->getKanban();

    expect($board->hasQuery())->toBeTrue()
        ->and($board->baseQuery()->pluck('title')->all())->toBe(['Mine'])
        ->and(array_column($board->getCards('todo'), 'title'))->toBe(['Mine']);
});

it('keeps the page\'s own query when it sets one', function () {
    $project = project('Acme');
    task('Mine');
    task('Theirs', attributes: ['project_id' => $project->id]);
    TaskBoardPage::$query = fn () => Task::query()->whereNotNull('project_id');

    expect(app(TaskBoardPage::class)->getKanban()->baseQuery()->pluck('title')->all())->toBe(['Theirs']);
});

it('evaluates the resource\'s query on every call', function () {
    $board = app(TaskBoardPage::class)->getKanban();

    expect($board->getTotalCount())->toBe(0);

    task('Later');

    expect($board->getTotalCount())->toBe(1);
});

it('still requires a query on a board outside a resource', function () {
    Board::make()->columns([Column::make('todo')])->baseQuery();
})->throws(LogicException::class, 'needs a query()');

it('links the list page to the board with KanbanAction', function () {
    $action = KanbanAction::make()->resource(TaskResource::class);

    expect($action->getUrl())->toBe(url('/admin/tasks/board'))
        ->and($action->getLabel())->toBe('Board')
        ->and($action->isVisible())->toBeTrue();

    expect(KanbanAction::make()->resource(TaskResource::class)->page('index')->getUrl())->toBe(url('/admin/tasks'));
});

it('takes the resource from the page the action sits on', function () {
    $action = Livewire::test(ListTasks::class)->instance()->getAction('kanban');

    expect($action)->toBeInstanceOf(KanbanAction::class)
        ->and($action->getUrl())->toBe(url('/admin/tasks/board'));
});

it('hides KanbanAction when the resource has no board page', function () {
    $resource = new class extends TaskResource
    {
        public static function getPages(): array
        {
            return ['index' => ListTasks::route('/')];
        }
    };

    expect(KanbanAction::make()->resource($resource::class)->isVisible())->toBeFalse();
});

it('puts a Table link back to the list in the board page\'s header', function () {
    task('Mine');

    $page = Livewire::test(TaskBoardPage::class)
        ->assertSee('Mine')
        ->assertSeeHtml('href="'.url('/admin/tasks').'"');

    $action = $page->instance()->getAction('table');

    expect($action)->toBeInstanceOf(TableAction::class)
        ->and($action->getLabel())->toBe('Table')
        ->and($action->getUrl())->toBe(url('/admin/tasks'))
        ->and($action->isVisible())->toBeTrue();
});

it('hides the Table link when the resource has no index page', function () {
    $resource = new class extends TaskResource
    {
        public static function getPages(): array
        {
            return ['kanban' => TaskBoardPage::route('/board')];
        }
    };

    expect(TableAction::make()->resource($resource::class)->isVisible())->toBeFalse();
});

it('counts the visible columns\' cards in the navigation badge', function () {
    task('A');
    task('B', 'doing');
    task('C', 'done'); // hidden column
    task('D', attributes: ['project_id' => project('Acme')->id]); // outside the resource's query

    expect(TaskBoardPage::getNavigationBadge())->toBe('2');
});

it('leaves the badge alone, without a query, unless enabled', function () {
    $page = new class extends KanbanResourcePage
    {
        protected static string $resource = TaskResource::class;

        public function kanban(Board $board): Board
        {
            return $board->columns([Column::make('todo')]);
        }
    };

    DB::enableQueryLog();

    expect($page::getNavigationBadge())->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

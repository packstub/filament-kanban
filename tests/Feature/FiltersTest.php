<?php

use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use Packstub\Kanban\Board;
use Packstub\Kanban\Enums\FiltersLayout;
use Packstub\Kanban\Filter;
use Packstub\Kanban\Tests\Fixtures\PlainBoard;
use Packstub\Kanban\Tests\Fixtures\Project;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\Fixtures\TaskBoard;

/*
 * The Filter class shared with Gantt and Spreadsheet: every type, what the URL gives, what the
 * browser gets; the renderless calls behind Filament's select; where the view puts the filters.
 */

it('narrows with a plain select, one of its options and nothing else', function () {
    $acme = project('Acme');
    task('A', 'todo', ['project_id' => $acme->id]);
    task('B');

    $filter = Filter::select('project_id')->options([$acme->id => 'Acme']);
    $board = board()->filters([$filter]);

    expect($filter->getType())->toBe('select')
        ->and($filter->isSelect())->toBeTrue()
        ->and($filter->isMultiple())->toBeFalse()
        ->and($filter->isSearchable())->toBeFalse()
        ->and($filter->getLabel())->toBe('Project')
        ->and(array_column($board->getCards('todo', filters: ['project_id' => (string) $acme->id]), 'title'))->toBe(['A'])
        ->and(array_column($board->getCards('todo', filters: ['project_id' => '999']), 'title'))->toBe(['A', 'B'])
        ->and(array_column($board->getCards('todo', filters: ['project_id' => ['nested']]), 'title'))->toBe(['A', 'B']);
});

it('takes any key for a searchable select, since its values come from a search', function () {
    $acme = project('Acme');
    $globex = project('Globex');
    task('A', 'todo', ['project_id' => $acme->id]);
    task('B', 'todo', ['project_id' => $globex->id]);
    task('C');

    $filter = Filter::select('project_id')->searchable()->options([$acme->id => 'Acme']);
    $board = board()->filters([$filter]);

    expect($filter->isSearchable())->toBeTrue()
        ->and(array_column($board->getCards('todo', filters: ['project_id' => (string) $globex->id]), 'title'))->toBe(['B']) // not among the listed options, still applied
        ->and(array_column($board->getCards('todo', filters: ['project_id' => '']), 'title'))->toBe(['A', 'B', 'C'])
        ->and($filter->state((string) $globex->id))->toBe((string) $globex->id)
        ->and(Filter::select('x')->searchable(fn () => false)->isSearchable())->toBeFalse();

    $multiple = Filter::select('project_id')->multiple()->searchable()->options([$acme->id => 'Acme']);

    expect($multiple->state([(string) $globex->id, $acme->id, '', (string) $globex->id]))->toBe([(string) $globex->id, (string) $acme->id])
        ->and(array_column(board()->filters([$multiple])->getCards('todo', filters: ['project_id' => [(string) $globex->id]]), 'title'))->toBe(['B']);
});

it('searches the options by label or through getSearchResultsUsing, and labels a value through getOptionLabelUsing', function () {
    $filter = Filter::select('owner')->options([1 => 'Ana', 2 => 'Dan', 3 => 'Dana'])->optionsLimit(2);

    expect($filter->getSearchResults('dan'))->toBe([2 => 'Dan', 3 => 'Dana'])
        ->and($filter->getSearchResults(''))->toBe([1 => 'Ana', 2 => 'Dan'])
        ->and($filter->getOptionLabel(2))->toBe('Dan')
        ->and($filter->getOptionLabel('9'))->toBeNull()
        ->and($filter->getOptionsLimit())->toBe(2)
        ->and(Filter::select('x')->optionsLimit(0)->getOptionsLimit())->toBe(1);

    $custom = Filter::select('owner')->searchable()->preload(false)
        ->getSearchResultsUsing(fn (string $search) => ['7' => 'Found '.$search])
        ->getOptionLabelUsing(fn (string $value) => $value === '7' ? 'Seven' : null);

    expect($custom->getSearchResults('x'))->toBe(['7' => 'Found x'])
        ->and($custom->getOptionLabel('7'))->toBe('Seven')
        ->and($custom->getOptionLabel('8'))->toBeNull()
        ->and($custom->isPreloaded())->toBeFalse();
});

it('gives a boolean filter a Yes / No select and its query a bool', function () {
    task('Urgent', 'todo', ['priority' => 9]);
    task('Calm', 'todo', ['priority' => 1]);
    $received = [];

    $filter = Filter::boolean('urgent')->query(function (Builder $query, bool $on) use (&$received) {
        $received[] = $on;
        $query->where('priority', $on ? '>' : '<=', 5);
    });
    $board = board()->filters([$filter]);

    expect($filter->getType())->toBe('boolean')
        ->and($filter->isBoolean())->toBeTrue()
        ->and($filter->getOptions())->toBe(['1' => 'Yes', '0' => 'No'])
        ->and(array_column($board->getCards('todo', filters: ['urgent' => '1']), 'title'))->toBe(['Urgent'])
        ->and(array_column($board->getCards('todo', filters: ['urgent' => '0']), 'title'))->toBe(['Calm'])
        ->and(array_column($board->getCards('todo', filters: ['urgent' => 'maybe']), 'title'))->toBe(['Urgent', 'Calm'])
        ->and($received)->toBe([true, false])
        ->and($filter->state('0'))->toBe('0')
        ->and($filter->state('2'))->toBe('');

    // The default narrows the column itself to true (1) or false (0).
    expect(array_column(board()->filters([Filter::boolean('priority')])->getCards('todo', filters: ['priority' => '1']), 'title'))->toBe(['Calm'])
        ->and(array_column(board()->filters([Filter::boolean('priority')])->getCards('todo', filters: ['priority' => '0']), 'title'))->toBe([]);
});

it('narrows a date range to real calendar days, either side open, and refuses a rolled-over date', function () {
    task('Jan', 'todo', ['due_at' => '2026-01-15']);
    task('Feb', 'todo', ['due_at' => '2026-02-10']);
    task('Mar', 'todo', ['due_at' => '2026-03-01']);
    task('None');

    $filter = Filter::dateRange('due_at');
    $board = board()->filters([$filter]);
    $titles = fn (array $value) => array_column($board->getCards('todo', filters: ['due_at' => $value]), 'title');

    expect($filter->getType())->toBe('dateRange')
        ->and($filter->isDateRange())->toBeTrue()
        ->and($filter->getOptions())->toBe([])
        ->and($filter->getLabel())->toBe('Due')
        ->and($titles(['from' => '2026-02-01', 'until' => '2026-02-28']))->toBe(['Feb'])
        ->and($titles(['from' => '2026-02-10']))->toBe(['Feb', 'Mar']) // the first day is included
        ->and($titles(['until' => '2026-02-10']))->toBe(['Jan', 'Feb']) // and so is the last
        ->and($titles(['from' => '2026-02-30']))->toBe(['Jan', 'Feb', 'Mar', 'None']) // not a date: ignored
        ->and($titles(['from' => '2026-1-5']))->toBe(['Jan', 'Feb', 'Mar', 'None'])
        ->and($titles(['from' => ['2026-01-01']]))->toBe(['Jan', 'Feb', 'Mar', 'None'])
        ->and($titles([]))->toBe(['Jan', 'Feb', 'Mar', 'None'])
        ->and($filter->state(['from' => '2026-02-30', 'until' => '2026-03-01']))->toBe(['from' => null, 'until' => '2026-03-01'])
        ->and($filter->state('2026-03-01'))->toBe(['from' => null, 'until' => null])
        ->and($filter->emptyState())->toBe(['from' => null, 'until' => null])
        ->and(Filter::date('2026-02-29'))->toBeNull()
        ->and(Filter::date('2024-02-29'))->toBe('2024-02-29')
        ->and(Filter::date('0999-01-01'))->toBeNull();

    $received = null;
    board()->filters([Filter::dateRange('due_at')->query(function (Builder $query, array $range) use (&$received) {
        $received = $range;
    })])->getCards('todo', filters: ['due_at' => ['until' => '2026-02-10']]);

    expect($received)->toBe(['from' => null, 'until' => '2026-02-10']);
});

it('gives each filter type its empty state', function () {
    expect(Filter::select('a')->emptyState())->toBe('')
        ->and(Filter::select('a')->multiple()->emptyState())->toBe([])
        ->and(Filter::boolean('a')->emptyState())->toBe('')
        ->and(Filter::toggle('a')->emptyState())->toBeFalse()
        ->and(Filter::dateRange('a')->emptyState())->toBe(['from' => null, 'until' => null]);
});

it('describes itself to the browser', function () {
    $acme = project('Acme');

    expect(Filter::select('project_id')->multiple()->options(fn () => [$acme->id => 'Acme'])->toArray())->toBe([
        'name' => 'project_id',
        'label' => 'Project',
        'type' => 'select',
        'multiple' => true,
        'searchable' => false,
        'optionsLimit' => 50,
        'options' => [['value' => (string) $acme->id, 'label' => 'Acme']],
    ])
        ->and(Filter::select('owner')->searchable()->preload(false)->options([1 => 'Ana'])->toArray()['options'])->toBe([]) // the search fetches them
        ->and(Filter::select('owner')->searchable()->options([1 => 'Ana'])->toArray()['options'])->toBe([['value' => '1', 'label' => 'Ana']])
        ->and(Filter::select('owner')->options([1 => 'Ana', 2 => 'Dan'])->optionsLimit(1)->toArray()['options'])->toBe([['value' => '1', 'label' => 'Ana']])
        ->and(Filter::boolean('active')->toArray())->toMatchArray(['type' => 'boolean', 'options' => [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']]])
        ->and(Filter::toggle('mine')->toArray())->toMatchArray(['type' => 'toggle', 'multiple' => false, 'options' => []])
        ->and(Filter::dateRange('due_at')->label('Due date')->toArray())->toMatchArray(['type' => 'dateRange', 'label' => 'Due date', 'options' => []]);
});

it('answers the options, a search and the labels for the browser\'s select', function () {
    $acme = project('Acme');
    $globex = project('Globex');

    Livewire::test(TaskBoard::class)
        ->call('kanbanFilterOptions', 'project_id')
        ->assertReturned([['value' => (string) $acme->id, 'label' => 'Acme'], ['value' => (string) $globex->id, 'label' => 'Globex']])
        ->call('kanbanFilterOptions', 'project_id', 'glo')
        ->assertReturned([['value' => (string) $globex->id, 'label' => 'Globex']])
        ->call('kanbanFilterOptions', 'urgent') // a toggle has no options
        ->assertReturned([])
        ->call('kanbanFilterOptions', 'nope')
        ->assertReturned([])
        ->call('kanbanFilterOptions', ['x'])
        ->assertReturned([])
        ->call('kanbanFilterOptions', 'project_id', ['x'])
        ->assertReturned([])
        ->call('kanbanFilterOptionLabel', 'project_id', (string) $acme->id)
        ->assertReturned('Acme')
        ->call('kanbanFilterOptionLabel', 'project_id', '999')
        ->assertReturned(null)
        ->call('kanbanFilterOptionLabel', 'project_id', '')
        ->assertReturned(null)
        ->call('kanbanFilterOptionLabel', 'urgent', '1')
        ->assertReturned(null)
        // A list, as Filament's select reads it (`option.value`, `option.label`), in the order asked.
        ->call('kanbanFilterOptionLabels', 'project_id', [(string) $globex->id, '999', (string) $acme->id, '', ['x']])
        ->assertReturned([['value' => (string) $globex->id, 'label' => 'Globex'], ['value' => (string) $acme->id, 'label' => 'Acme']])
        ->call('kanbanFilterOptionLabels', 'project_id', 'not a list')
        ->assertReturned([])
        ->call('kanbanFilterOptionLabels', 'nope', ['1'])
        ->assertReturned([]);
});

it('draws a shared link with a date range and a boolean from the URL', function () {
    task('Jan', 'todo', ['due_at' => '2026-01-15', 'priority' => 9]);
    task('Feb', 'todo', ['due_at' => '2026-02-10', 'priority' => 9]);
    task('Calm', 'todo', ['due_at' => '2026-02-11', 'priority' => 1]);

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->filters([
                Filter::dateRange('due_at'),
                Filter::boolean('urgent')->query(fn (Builder $query, bool $on) => $query->where('priority', $on ? '>' : '<=', 5)),
            ]);
        }
    };

    Livewire::withQueryParams(['filters' => ['due_at' => ['from' => '2026-02-01', 'until' => '2026-02-30'], 'urgent' => '1']])
        ->test($component::class)
        ->assertSee('Feb')
        ->assertDontSee('Jan')
        ->assertDontSee('Calm')
        ->assertSeeHtml('\u0022due_at\u0022:{\u0022from\u0022:\u00222026-02-01\u0022,\u0022until\u0022:null}')
        ->assertSeeHtml('\u0022urgent\u0022:\u00221\u0022');
});

it('places the filters where the definition or the config says, and refuses a layout that is not one', function () {
    expect(Livewire::test(TaskBoard::class)->instance()->getKanban()->getFiltersLayout())->toBe(FiltersLayout::Dropdown)
        ->and(board()->filtersLayout(FiltersLayout::Toolbar)->getFiltersLayout())->toBe(FiltersLayout::Toolbar)
        ->and(board()->filtersLayout('above')->getFiltersLayout())->toBe(FiltersLayout::AboveContent)
        ->and(board()->filtersLayout(null)->getFiltersLayout())->toBe(FiltersLayout::Dropdown);

    config()->set('packstub-kanban.filters_layout', 'toolbar');
    expect(board()->getFiltersLayout())->toBe(FiltersLayout::Toolbar)
        ->and(board()->filtersLayout('dropdown')->getFiltersLayout())->toBe(FiltersLayout::Dropdown)
        ->and(Livewire::test(TaskBoard::class)->instance()->getKanbanConfig()['filtersLayout'])->toBe('toolbar');

    config()->set('packstub-kanban.filters_layout', 'sideways');
    expect(fn () => board()->getFiltersLayout())->toThrow(InvalidArgumentException::class, 'sideways');
});

it('renders the funnel only with filters, the fields inline in the toolbar, or a row above the board', function () {
    $html = Livewire::mount(TaskBoard::class);

    foreach ([
        'class="ps-kanban"', 'ps-kanban-toolbar', 'ps-kanban-toolbar-start', 'ps-kanban-toolbar-end', 'ps-kanban-sidebar-btn', 'ps-kanban-search',
        'fi-input-wrp', 'fi-input-has-inline-prefix', 'ps-kanban-filters-dropdown', 'ps-kanban-filters-btn', 'ps-kanban-filters-panel', 'ps-kanban-filters-reset',
        'ps-kanban-filter', 'fi-select-input', 'ps-kanban-toggle', 'fi-checkbox-input', 'ps-kanban-density-btn', 'ps-kanban-columns-dropdown', 'ps-kanban-columns-btn',
        'ps-kanban-columns-item', 'ps-kanban-indicator', 'ps-kanban-bulk-move', 'ps-kanban-bulk-action', 'ps-kanban-selection-clear', 'ps-kanban-tabs', 'fi-tabs-item',
        'ps-kanban-board', 'ps-kanban-card-popover', 'fi-dropdown-panel', 'fi-dropdown-list-item', 'fi-dropdown-header', 'fi-btn', 'fi-icon-btn', 'fi-badge', 'fi-dropdown',
    ] as $hook) {
        expect($html)->toContain($hook);
    }

    expect($html)->not->toContain('ps-kanban-filters-inline')->not->toContain('ps-kanban-filters-above');

    // No filters, no funnel; no search, no search field; no focus mode, no sidebar button.
    $plain = Livewire::mount(PlainBoard::class);
    expect($plain)->not->toContain('ps-kanban-filters-dropdown')->not->toContain('ps-kanban-search')->toContain('ps-kanban-columns-btn');

    $inline = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->filtersLayout(FiltersLayout::Toolbar)->focusMode(false);
        }
    };
    $html = Livewire::test($inline::class)->html();
    expect($html)->toContain('ps-kanban-filters-inline')->not->toContain('ps-kanban-filters-dropdown')->not->toContain('ps-kanban-filters-above')->not->toContain('ps-kanban-sidebar-btn');

    $above = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->filtersLayout(FiltersLayout::AboveContent);
        }
    };
    $html = Livewire::test($above::class)->html();
    expect($html)->toContain('ps-kanban-filters-above')->toContain('ps-kanban-filters-reset')->not->toContain('ps-kanban-filters-dropdown')->not->toContain('ps-kanban-filters-inline');

    // A date range draws Filament's date picker.
    $dated = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->filters([Filter::dateRange('due_at')]);
        }
    };
    expect(Livewire::test($dated::class)->html())->toContain('fi-fo-date-time-picker')->toContain('ps-kanban-range')->toContain('firstDayOfWeek: 1');
});

it('caps the values a searchable multiple filter, a label lookup and a search take', function () {
    $lookups = 0;
    $filter = Filter::select('owner')->multiple()->searchable()->optionsLimit(5)
        ->options(['a' => 'A'])
        ->getOptionLabelUsing(function (string $value) use (&$lookups) {
            $lookups++;

            return "L{$value}";
        })
        ->getSearchResultsUsing(fn (string $search) => collect(['s' => mb_strlen($search)]));

    $applied = null;
    $filter->query(function ($query, array $values) use (&$applied) {
        $applied = $values;
    });
    $filter->apply(Task::query(), array_map('strval', range(1, 50)));

    expect($applied)->toHaveCount(5)->and($applied)->toBe(['1', '2', '3', '4', '5']);

    $filter->apply(Task::query(), [str_repeat('x', 256), 'ok']);
    expect($applied)->toBe(['ok']);

    $component = new class extends TaskBoard
    {
        public static ?Filter $filter = null;

        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->filters([self::$filter]);
        }
    };
    $component::$filter = $filter;

    Livewire::test($component::class)
        ->call('kanbanFilterOptionLabels', 'owner', array_merge(array_map('strval', range(1, 40)), ['1', '1']))
        ->assertReturned([['value' => '1', 'label' => 'L1'], ['value' => '2', 'label' => 'L2'], ['value' => '3', 'label' => 'L3'], ['value' => '4', 'label' => 'L4'], ['value' => '5', 'label' => 'L5']])
        ->call('kanbanFilterOptions', 'owner', str_repeat('q', 500))
        ->assertReturned([['value' => 's', 'label' => '200']]);

    expect($lookups)->toBe(5);
});

it('answers Filament 4.16+ option requests through the kanbanFilters schema by key', function () {
    $acme = project('Acme');
    $beta = project('Beta');

    $component = new class extends TaskBoard
    {
        public function kanban(Board $board): Board
        {
            return parent::kanban($board)->filters([Filter::select('project_id')->searchable()->options(fn () => Project::query()->orderBy('name')->pluck('name', 'id')->all())]);
        }
    };

    Livewire::test($component::class)
        ->call('callSchemaComponentMethod', 'kanbanFilters.project_id', 'getOptionsForJs')
        ->assertReturned([['value' => (string) $acme->id, 'label' => 'Acme'], ['value' => (string) $beta->id, 'label' => 'Beta']])
        ->call('callSchemaComponentMethod', 'kanbanFilters.project_id', 'getSearchResultsForJs', ['search' => 'bet'])
        ->assertReturned([['value' => (string) $beta->id, 'label' => 'Beta']])
        ->call('callSchemaComponentMethod', 'kanbanFilters.project_id', 'getOptionLabelsForJs')
        ->assertReturned([])
        ->call('callSchemaComponentMethod', 'kanbanFilters.nope', 'getOptionsForJs')
        ->assertReturned(null);
});

<?php

use Packstub\Kanban\Tests\Fixtures\TaskBoard;

it('ships every string in all four languages', function () {
    $keys = collect(['en', 'ro', 'ru', 'de'])
        ->mapWithKeys(fn (string $locale) => [$locale => array_keys(require __DIR__."/../../resources/lang/{$locale}/kanban.php")]);

    expect($keys['en'])->toContain('column_label', 'moved_to', 'loaded_more', 'matches_count', 'compact', 'comfortable');

    foreach ($keys->except('en') as $locale => $translated) {
        expect(array_diff($keys['en'], $translated))->toBe([], "{$locale} is missing strings")
            ->and(array_diff($translated, $keys['en']))->toBe([], "{$locale} has strings en does not");
    }
});

it('gives every counted announcement its plural forms, and the browser the locale to pick them', function () {
    foreach (['en' => 2, 'de' => 2, 'ro' => 3, 'ru' => 3] as $locale => $forms) {
        $strings = require __DIR__."/../../resources/lang/{$locale}/kanban.php";

        foreach (['column_label', 'loaded_more', 'matches_count'] as $key) {
            expect(explode('|', $strings[$key]))->toHaveCount($forms, "{$locale}.{$key}");
        }
    }

    app()->setLocale('ro');

    expect(Livewire\Livewire::test(TaskBoard::class)->instance()->getKanbanConfig()['locale'])->toBe('ro');
});

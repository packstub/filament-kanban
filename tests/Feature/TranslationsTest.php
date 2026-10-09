<?php

it('ships every string in all four languages', function () {
    $keys = collect(['en', 'ro', 'ru', 'de'])
        ->mapWithKeys(fn (string $locale) => [$locale => array_keys(require __DIR__."/../../resources/lang/{$locale}/kanban.php")]);

    expect($keys['en'])->toContain('column_label', 'moved_to', 'loaded_more', 'matches_count', 'compact', 'comfortable');

    foreach ($keys->except('en') as $locale => $translated) {
        expect(array_diff($keys['en'], $translated))->toBe([], "{$locale} is missing strings")
            ->and(array_diff($translated, $keys['en']))->toBe([], "{$locale} has strings en does not");
    }
});

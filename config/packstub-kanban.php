<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    |
    | Where a board shows its filters: `dropdown` behind a funnel button in the
    | toolbar with the count of active filters (as a table), `toolbar` inline
    | after the search box, `above` on a row of their own between the toolbar
    | and the columns. A board's filtersLayout() wins.
    |
    */

    'filters_layout' => 'dropdown',

    /*
    |--------------------------------------------------------------------------
    | Dates
    |--------------------------------------------------------------------------
    |
    | The PHP date format a date-range filter's pickers display (null: `M j, Y`),
    | and the ISO weekday their calendars start on (1 = Monday … 7 = Sunday).
    |
    */

    'date_format' => null,

    'first_day_of_week' => 1,

];

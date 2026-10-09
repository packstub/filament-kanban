<?php

namespace Packstub\Kanban\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** A task whose priority is cast to the Priority enum (for lanes derived from an enum-cast attribute). */
class RankedTask extends Model
{
    protected $table = 'tasks';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['priority' => Priority::class];
}

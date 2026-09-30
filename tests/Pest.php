<?php

use Packstub\Kanban\Tests\Fixtures\Project;
use Packstub\Kanban\Tests\Fixtures\Task;
use Packstub\Kanban\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/** @param  array<string, mixed>  $attributes */
function task(string $title, string $status = 'todo', array $attributes = []): Task
{
    return Task::query()->create(['title' => $title, 'status' => $status, ...$attributes]);
}

function project(string $name): Project
{
    return Project::query()->create(['name' => $name]);
}

<?php

namespace Packstub\Kanban\Tests\Fixtures;

/** Registered by a test (Gate::policy) for the tasks the user may or may not update. */
class TaskPolicy
{
    public static bool $canUpdate = true;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Task $task): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Task $task): bool
    {
        return static::$canUpdate;
    }

    public function delete(User $user, Task $task): bool
    {
        return true;
    }
}

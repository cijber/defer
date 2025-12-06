<?php

namespace Cijber\Defer\Scheduler;

use Fiber;

class Task
{
    public int $id;
    public TaskState $state = TaskState::Pending;
    public Fiber $fiber;

    public function __construct(callable $func)
    {
        $this->id = spl_object_id($this);
        $this->fiber = new Fiber($func);
    }
}
<?php

namespace Cijber\Defer\Scheduler;

use Cijber\Defer\Scheduler;

class Waker
{
    public readonly int $id;

    public function __construct(
        private array $tasks = []
    )
    {
        $this->id = spl_object_id($this);
    }

    /**
     * Add a task to be woken up
     * @param Task $task
     * @return void
     */
    function addTask(Task $task): void
    {
        $this->tasks[$task->id] = $task;
    }

    /**
     * @return array
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    function wakeUp(Scheduler $scheduler): void
    {
        foreach ($this->tasks as $task) {
            $scheduler->enqueue($task);
        }
    }
}
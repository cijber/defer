<?php

namespace Cijber\Defer;

use Cijber\Defer\Scheduler\Continuation;
use Cijber\Defer\Scheduler\Continuation\Dependency;
use Cijber\Defer\Scheduler\Continuation\Hibernate;
use Cijber\Defer\Scheduler\Task;
use Cijber\Defer\Scheduler\TaskState;
use Cijber\Defer\Scheduler\Waker;
use Cijber\Defer\Scheduler\Waker\EffectWaker;
use Cijber\Defer\Utils\HRTime;
use Fiber;
use RuntimeException;
use SplMaxHeap;
use SplPriorityQueue;

class Scheduler
{
    private static ?Scheduler $current = null;

    public static function live(): Scheduler
    {
        if (static::$current === null) {
            static::$current = new Scheduler();
        }

        return static::$current;
    }

    public static function inContext(): bool
    {
        return static::live()->task !== null;
    }

    private array $map = [];
    /**
     * @var array<int, Waker>
     */
    private array $wakers = [];
    /**
     * @var array<int, array<int,true>>
     */
    private array $wakeMap = [];
    /**
     * @var array<int, array<int,true>>
     */
    private array $reverseWakeMap = [];
    /**
     * @var SplPriorityQueue<int, Task>
     */
    private SplPriorityQueue $queue;
    /**
     * @var SplMaxHeap<[HRTime, int]>
     */
    private SplMaxHeap $timeQueue;
    private array $timerMap = [];
    private array $stack = [];
    private ?Task $task = null;


    private function __construct()
    {
        $this->queue = new SplPriorityQueue();
        $this->timeQueue = new SplMaxHeap();
    }

    public static function task(): ?Task
    {
        return static::$current?->task;
    }

    function enqueue(Task $task, int $priority = 100): void
    {
        if (isset($this->timerMap[$task->id])) {
            return;
        }

        if (isset($this->map[$task->id])) {
            return;
        }

        $this->map[$task->id] = $task;
        $this->queue->insert($task, $priority);
    }

    function hibernate(HRTime $until): void
    {
        if ($this->task !== null) {
            $this->hibernateTask($this->task, $until);
            Fiber::suspend();
            return;
        }


        while ($time = $this->timeToNext()) {
            $wait = $until->sub(HRTime::now());
            if ($wait > $time) {
                break;
            }

            $time->sleep();

            while($this->next()) {
                // todo
            }
        }

        $wait = $until->sub(HRTime::now());
        $wait->sleep();
    }

    private function hibernateTask(Task $task, HRTime $until): void
    {
        $waker = new Waker([$task]);
        $this->timeQueue->insert([$until, $waker]);
        $this->timerMap[$waker->id] = $until;
    }

    private function next(): bool
    {
        $task = $this->dequeue();
        if ($task !== null) {
            $this->enter($task);
            return true;
        }

        return false;
    }

    private function timeToNext(): ?HRTime
    {
        if ($this->queue->valid()) {
            return new HRTime(0);
        }

        if ($this->timeQueue->valid()) {
            [$time, $_] = $this->timeQueue->top();
            $now = HRTime::now();
            return $now->sub($time);
        }

        return null;
    }

    private function dequeue(): ?Task
    {
        if ($waker = $this->dequeueTimer()) {
            $this->wakeUp($waker);
        }

        return $this->dequeueTask();
    }

    private function dequeueTask(): ?Task
    {
        if (!$this->queue->valid()) {
            return null;
        }

        return $this->queue->extract();
    }

    private function dequeueTimer(): ?Waker
    {
        if (!$this->timeQueue->valid()) {
            return null;
        }

        [$time, $waker] = $this->timeQueue->top();
        if ($time <= HRTime::now()) {
            $this->timeQueue->extract();
            return $waker;
        }

        return null;
    }

    private function enter(Task $task): void
    {
        if ($this->task !== null) {
            $this->stack[] = $this->task;
            $this->task = $task;
        } else {
            $this->task = $task;
        }

        $this->handleTask($task);
        $this->leave();
    }

    private function handleTask(Task $task): void
    {
        unset($this->map[$task->id]);

        if (!$task->fiber->isStarted()) {
            $task->fiber->start();
        } else if ($task->fiber->isSuspended()) {
            $task->fiber->resume();
        }

        if ($task->fiber->isTerminated()) {
            $this->finishTask($task);
        }
    }

    private function finishTask(Task $task): void
    {
        $task->state = TaskState::Done;
        unset($this->map[$task->id]);
        $this->wakeUpDependencies($task);
    }

    public function wakeUp(Waker $waker): void
    {
        $waker->wakeUp($this);
        if (array_key_exists($waker->id, $this->reverseWakeMap)) {
            $toRemove = $this->reverseWakeMap[$waker->id];
            foreach ($toRemove as $tId => $_) {
                unset($this->wakeMap[$tId][$waker->id]);
                if (count($this->wakeMap[$tId]) > 0) {
                    unset($this->wakeMap[$tId]);
                }
            }
        }
    }

    private function wakeUpDependencies(Task $task): void
    {
        if (!array_key_exists($task->id, $this->wakeMap)) {
            return;
        }

        $deps = $this->wakeMap[$task->id];
        foreach ($deps as $depId => $_) {
            $this->wakeUp($this->wakers[$depId]);
        }
    }

    private function leave()
    {
        $this->task = array_pop($this->stack);
    }

    public static function suspend(): void
    {
        if (static::task() === null) {
            throw new RuntimeException("cant suspend main thread");
        }

        Fiber::suspend();
    }

    public function wait(Task $task): void
    {
        $this->enqueue($task);

        if ($this->task === null) {
            $this->liveWait($task);
            return;
        }

        $waker = new Waker([$this->task]);
        $this->waitForTask($task, $waker);
        Fiber::suspend();
    }

    public function addWaker(Waker $waker): void
    {
        if (array_key_exists($waker->id, $this->wakers)) {
            return;
        }

        $this->wakers[$waker->id] = $waker;
        foreach ($waker->getTasks() as $task) {
            if (array_key_exists($task->id, $this->reverseWakeMap)) {
                $this->reverseWakeMap[$task->id] = [];
            }

            $this->reverseWakeMap[$task->id][$waker->id] = $waker->id;
        }
    }

    public function waitForTask(Task $task, Waker $waker): void
    {
        $this->addWaker($waker);
        if (array_key_exists($task->id, $this->wakeMap)) {
            $this->wakeMap[$task->id] = [];
        }

        $this->wakeMap[$task->id][$waker->id] = $waker->id;
    }

    private function liveWait(Task $task)
    {
        $zero = new HRTime(0);
        while ($task->state !== TaskState::Done) {
            $sleep = $this->timeToNext();
            if ($sleep === null) {
                throw new RuntimeException("Task didn't complete with nothing else todo in the queue");
            } else if ($sleep >= $zero) {
                $this->next();
            } else {
                $sleep->sleep();
            }
        }
    }
}
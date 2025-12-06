<?php

namespace Cijber\Defer;

use Cijber\Defer\Scheduler\Task;
use Cijber\Defer\Scheduler\Waker;
use Closure;
use RuntimeException;
use Throwable;
use function Cijber\Defer\task;

/**
 * @template TValue
 * @template TError
 *
 * @type Promise<TValue, TError>
 */
class Promise
{
    private ?Task $task = null;
    private ?Waker $waker = null;
    private PromiseState $state = PromiseState::Pending;

    /**
     * @var TValue|TError
     */
    private mixed $value = null;
    public function __construct(?callable $f = null, bool $queue = true)
    {
        if ($f !== null) {
            $this->task = new Task(function () use ($f) {
                try {
                    $this->resolve($f());
                } catch (Throwable $t) {
                    $this->reject($t);
                }
            });

            if ($queue) {
                scheduler()->enqueue($this->task);
            }
        }
    }

    /**
     * @return TValue
     * @throws TError
     */
    function await()
    {
        if ($this->state !== PromiseState::Pending) {
            return $this->return();
        }

        if ($this->task === null) {
            if ($this->waker === null) {
                $this->waker = new Waker();
            }

            $task = task();
            if ($task === null) {
                await(function () {
                    if ($this->waker === null) {
                        return;
                    }

                    $this->waker->addTask(task());
                    suspend();
                });
            } else {
                $this->waker->addTask($task);
                suspend();
            }
        } else {
            scheduler()->wait($this->task);
        }

        return $this->return();
    }

    /**
     * @return TValue|null
     * @throws TError
     */
    private function return()
    {
        if ($this->state === PromiseState::Resolved) {
            return $this->value;
        }

        if ($this->state === PromiseState::Rejected) {
            throw $this->value;
        }

        throw new RuntimeException("promise not resolved yet");
    }

    public static function resolved($value): Promise
    {
        $promise = new Promise();
        $promise->resolve($value);
        return $promise;
    }

    /**
     * @param TValue $value
     * @return void
     */
    public function resolve($value): void
    {
        if ($this->state !== PromiseState::Pending) {
            throw new RuntimeException("can't resolve promise that's already resolved");
        }

        $this->state = PromiseState::Resolved;
        $this->value = $value;
        if ($this->waker !== NULL) {
            Scheduler::live()->wakeUp($this->waker);
            $this->waker = null;
        }
    }

    /**
     * @param TValue $value
     * @return void
     */
    public function reject($value): void
    {
        $this->state = PromiseState::Rejected;
        $this->value = $value;
    }

    /**
     * @return PromiseState
     */
    public function getState(): PromiseState
    {
        return $this->state;
    }

    /**
     * @template TResult
     * @param Closure<TResult, TValue> $f
     * @return Promise<TResult>
     */
    public function then(Closure $f): Promise
    {
        return new Promise(function () use ($f) {
            $res = $this->await();
            return $f($res);
        });
    }

    public static function all(array $items): Promise
    {
        $done = [];
        $todo = count($items);

        $n = new Promise();

        foreach ($items as $key => $item) {
            if ($item instanceof Closure) {
                $promise = new Promise($item);
            } else if ($item instanceof Promise) {
                $promise = $item;
            } else {
                $done[$key] = $item;
                $todo--;
                continue;
            }

            $promise
                ->then(function () use (&$todo, &$done, $key, $promise, $n) {
                    $todo--;
                    $done[$key] = $promise->value;

                    if ($todo === 0) {
                        $n->resolve($done);
                    }
                });
        }

        if ($todo === 0) {
            $n->resolve($done);
        }

        return $n;
    }

    /***
     * @template TInput of array<Promise<TValue>|Closure<TValue>|TValue>
     * @template TKey key-of<TInput>
     * @param TInput $items
     * @return Promise<null|list{TKey, TValue}>
     */
    public static function pick(array $items): Promise
    {
        if (count($items) === 0) {
            return Promise::resolved(null);
        }

        $promiseList = [];
        foreach ($items as $key => $item) {
            if ($item instanceof Closure) {
                $promise = new Promise($item);
            } else if ($item instanceof Promise) {
                $promise = $item;
            } else {
                return Promise::resolved([$key, $item]);
            }

            $promiseList[$key] = $promise;
        }

        $p = new Promise();
        foreach ($promiseList as $key => $promise) {
            $promise->then(function($value) use ($key, $p) {
                if ($p->state != PromiseState::Pending) {
                    return;
                }

                $p->resolve([$key, $value]);
            });
        }

        return $p;
    }
}
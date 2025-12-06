<?php

namespace Cijber\Defer;

use Cijber\Defer\Scheduler\Task;
use Cijber\Defer\Utils\HRTime;


function defer(callable $f): Promise
{
    return new Promise($f);
}

function await(callable|Promise $f)
{
    if ($f instanceof Promise) {
        return $f->await();
    }

    return defer($f)->await();
}

function scheduler(): Scheduler
{
    return Scheduler::live();
}

function task(): ?Task
{
    return Scheduler::task();
}

function suspend(): void
{
    Scheduler::suspend();
}

function rest(int $sec, int $nanos = 0): void
{
    $sch = scheduler();
    $wait = HRTime::now()->add(new HRTime($sec, $nanos));
    $sch->hibernate($wait);
}
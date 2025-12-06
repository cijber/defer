<?php

namespace Cijber\Defer\Scheduler;

enum TaskState
{
    case Pending;
    case Running;
    case Done;
}

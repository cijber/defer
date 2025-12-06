<?php

namespace Cijber\Defer;

enum PromiseState
{
    case Pending;
    case Resolved;
    case Rejected;
}

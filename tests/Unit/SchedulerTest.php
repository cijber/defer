<?php

use Cijber\Defer\Utils\HRTime;
use function Cijber\Defer\await;
use function Cijber\Defer\defer;
use function Cijber\Defer\rest;

test('scheduler works', function () {
    $result = defer(fn() => 4)->await();
    expect($result)->toBe(4);
});

test('rest works', function () {

    $diff = await(function () {
        $start = HRTime::now();
        rest(1);
        $end = HRTime::now();
        return $end->sub($start);
    });

    expect($diff->toNumber())->toBeBetween((new HRTime(0, 900_000))->toNumber(), (new HRTime(2))->toNumber());
});

test('dependency works', function () {
    $result = defer(fn() => 4)
        ->then(fn($a) => $a * 3)
        ->then(fn($b) => $b + 3)
        ->await();

    expect($result)->toBe(15);
});
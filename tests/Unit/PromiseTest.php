<?php

use Cijber\Defer\Promise;
use function Cijber\Defer\defer;
use function Cijber\Defer\rest;

test('promise all', function () {
    $p = Promise::all([
        '1' => fn() => 1,
        '2' => fn() => rest(0, 300_000),
        '3' => 3,
    ]);


    expect($p->await())->toMatchArray([
        '1' => 1,
        '2' => null,
        '3' => 3,
    ]);
});

test('promise resolved', function () {
    $p = Promise::resolved(2);
    expect($p->await())->toBe(2);
});

describe('promise pick', function () {
    it('instantly resolved', function () {
        $p = Promise::pick([
            '1' => 1,
        ]);

        expect($p->await())->toMatchArray(['1', 1]);
    });

    it('picks right', function () {
        $p = Promise::pick([
            '1' => function () {
                rest(1);
                return 3;
            },
            '2' => function () {
                return 2;
            },
        ]);

        expect($p->await())->toMatchArray(['2', 2]);
    });
});

test('test deferred', function () {
    $p = new Promise();
    defer(function () use ($p) {
        $p->resolve("wow!");
    });

    $w = $p->await();
    expect($w)->toBe("wow!");
});
<?php

use Hassam\DataAccessWrapper\Cache\ListCache;

it('stores and retrieves an ID list with its total', function () {
    $list = new ListCache('orders');
    $list->put(['page' => 1, 'status' => 'paid'], [3, 1, 2], 3);

    expect($list->get(['page' => 1, 'status' => 'paid']))
        ->toBe(['ids' => [3, 1, 2], 'total' => 3]);
});

it('returns null on miss', function () {
    expect((new ListCache('orders'))->get(['page' => 99]))->toBeNull();
});

it('keys distinct param sets separately', function () {
    $list = new ListCache('orders');
    $list->put(['page' => 1], [1], 1);
    $list->put(['page' => 2], [2], 1);

    expect($list->get(['page' => 1])['ids'])->toBe([1]);
    expect($list->get(['page' => 2])['ids'])->toBe([2]);
});

it('flushing one model list does not wipe another model list', function () {
    $orders = new ListCache('orders');
    $items = new ListCache('order_items');

    $orders->put(['page' => 1], [1, 2], 2);
    $items->put(['page' => 1], [9], 1);

    $orders->flush();

    expect($orders->get(['page' => 1]))->toBeNull();
    expect($items->get(['page' => 1]))->not->toBeNull();
});

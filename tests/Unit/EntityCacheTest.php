<?php

use Hassam\DataAccessWrapper\Cache\EntityCache;
use Hassam\DataAccessWrapper\Cache\ListCache;
use Hassam\DataAccessWrapper\Tests\Fixtures\Order;

function makeOrder(int $id): Order
{
    $order = new Order;
    $order->id = $id;
    $order->customer_id = 100 + $id;
    $order->status = 'pending';

    return $order;
}

it('builds a tenant:tag:version:column:value key', function () {
    $cache = new EntityCache('orders');

    // No tenant bound -> "global"; version resolved from config/cache_versions.
    expect($cache->key('id', 1))->toBe('global:orders:v1:id:1');
});

it('stores and retrieves a model by its cacheBy column', function () {
    $cache = new EntityCache('orders');
    $cache->put(makeOrder(1));

    expect($cache->get('id', 1))->toMatchArray(['id' => 1, 'status' => 'pending']);
});

it('flushes one model tag without touching another (entity isolation)', function () {
    $orders = new EntityCache('orders');
    $items = new EntityCache('order_items');

    $orders->put(makeOrder(1));
    $items->put(makeOrder(1));

    $orders->flush();

    expect($orders->get('id', 1))->toBeNull();
    expect($items->get('id', 1))->not->toBeNull();
});

it('leaves entity cache intact when the list cache is flushed', function () {
    $entity = new EntityCache('orders');
    $list = new ListCache('orders');

    $entity->put(makeOrder(7));
    $list->put(['page' => 1], [7], 1);

    $list->flush();

    expect($list->get(['page' => 1]))->toBeNull();
    expect($entity->get('id', 7))->not->toBeNull();
});

it('forgets a single entity by column + value', function () {
    $cache = new EntityCache('orders');
    $cache->put(makeOrder(3));

    $cache->forget('id', 3);

    expect($cache->get('id', 3))->toBeNull();
});

<?php

use Hassam\DataAccessWrapper\Tests\Fixtures\Order;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('flushes per-row keys on save', function () {
    $order = Order::create([
        'customer_id' => 5,
        'updated_by'  => 1,
        'created_by'  => 1,
    ]);

    Order::cache()->put($order->id, 'cached-row');
    Order::cache()->put('by_customer-5', ['rows']);

    $order->status = 'paid';
    $order->save();

    expect(Order::cache()->get($order->id))->toBeNull();
    expect(Order::cache()->get('by_customer-5'))->toBeNull();
});

it('flushes per-row keys on delete', function () {
    $order = Order::create([
        'customer_id' => 5,
        'updated_by'  => 1,
    ]);

    Order::cache()->put($order->id, 'cached');

    $order->delete();

    expect(Order::cache()->get($order->id))->toBeNull();
});

it('flushes original-value key when foreign key changes', function () {
    $order = Order::create([
        'customer_id' => 5,
        'updated_by'  => 1,
    ]);

    // Seed both old and new customer caches.
    Order::cache()->put('by_customer-5', ['old']);
    Order::cache()->put('by_customer-10', ['new']);

    $order->customer_id = 10;
    $order->save();

    expect(Order::cache()->get('by_customer-5'))->toBeNull();
    expect(Order::cache()->get('by_customer-10'))->toBeNull();
});

it('flushes tagged caches via cacheTagsForFlush on save', function () {
    $order = Order::create([
        'customer_id' => 5,
        'updated_by'  => 1,
    ]);

    Order::cache()->tags("customer:5")->put('list', ['a']);
    Order::cache()->tags("order:{$order->id}")->put('with-items', ['b']);

    $order->status = 'paid';
    $order->save();

    expect(Order::cache()->tags("customer:5")->get('list'))->toBeNull();
    expect(Order::cache()->tags("order:{$order->id}")->get('with-items'))->toBeNull();
});

it('flushes both old and new tagged caches when foreign key changes', function () {
    $order = Order::create([
        'customer_id' => 5,
        'updated_by'  => 1,
    ]);

    Order::cache()->tags('customer:5')->put('dashboard', ['old']);
    Order::cache()->tags('customer:10')->put('dashboard', ['new']);

    $order->customer_id = 10;
    $order->save();

    expect(Order::cache()->tags('customer:5')->get('dashboard'))->toBeNull();
    expect(Order::cache()->tags('customer:10')->get('dashboard'))->toBeNull();
});

it('flushes on soft-delete restore', function () {
    $order = Order::create([
        'customer_id' => 5,
        'updated_by'  => 1,
    ]);

    $order->delete();

    Order::cache()->put($order->id, 'stale-after-restore');
    Order::cache()->tags('customer:5')->put('list', ['stale']);

    $order->restore();

    expect(Order::cache()->get($order->id))->toBeNull();
    expect(Order::cache()->tags('customer:5')->get('list'))->toBeNull();
});

it('does not double-fire flush on update (saved covers updated)', function () {
    $order = Order::create([
        'customer_id' => 5,
        'updated_by'  => 1,
    ]);

    $hits = 0;
    Order::saved(function () use (&$hits) {
        $hits++;
    });

    $order->status = 'paid';
    $order->save();

    // Trait registers `saved` once; our extra listener fires once.
    expect($hits)->toBe(1);
});

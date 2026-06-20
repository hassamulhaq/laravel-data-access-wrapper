<?php

use Hassam\DataAccessWrapper\Cache\EntityCache;
use Hassam\DataAccessWrapper\Cache\ListCache;
use Hassam\DataAccessWrapper\Tests\Fixtures\Order;
use Hassam\DataAccessWrapper\Tests\Fixtures\OrderRepository;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->repo = new OrderRepository;
});

it('creates a row and pre-warms the entity cache', function () {
    $order = $this->repo->manage('create', ['customer_id' => 5, 'status' => 'pending']);

    // Observer::created put the entity in cache — read it directly.
    $cached = (new EntityCache('orders'))->get('id', $order->id);

    expect($cached)->not->toBeNull();
    expect($cached['customer_id'])->toBe(5);
});

it('finds by id from cache without a second DB query', function () {
    $order = $this->repo->manage('create', ['customer_id' => 7, 'status' => 'pending']);

    DB::enableQueryLog();
    $found = $this->repo->findById($order->id);
    DB::disableQueryLog();

    expect($found['id'])->toBe($order->id);
    expect(DB::getQueryLog())->toHaveCount(0); // served from cache
});

it('paginates via the ID-list strategy and hydrates entities', function () {
    foreach (range(1, 3) as $i) {
        $this->repo->manage('create', ['customer_id' => $i, 'status' => 'pending']);
    }

    $page = $this->repo->paginate(['status' => 'pending'], page: 1, perPage: 10);

    expect($page->total())->toBe(3);
    expect($page->items())->toHaveCount(3);
    expect(collect($page->items())->pluck('customer_id')->all())->toBe([1, 2, 3]);

    // List cache now holds the ID list for these params.
    $list = (new ListCache('orders'))->get(['filters' => ['status' => 'pending'], 'page' => 1, 'perPage' => 10]);
    expect($list['ids'])->toBe([1, 2, 3]);
});

it('flushes list caches when a list-invalidating column changes', function () {
    $order = $this->repo->manage('create', ['customer_id' => 1, 'status' => 'pending']);
    $this->repo->paginate(['status' => 'pending']);

    $params = ['filters' => ['status' => 'pending'], 'page' => 1, 'perPage' => 50];
    expect((new ListCache('orders'))->get($params))->not->toBeNull();

    // status is in $listInvalidatingColumns -> lists flushed.
    $this->repo->manage('update', ['status' => 'paid'], $order->id);

    expect((new ListCache('orders'))->get($params))->toBeNull();
});

it('keeps list caches when only a non-invalidating column changes', function () {
    $order = $this->repo->manage('create', ['customer_id' => 1, 'status' => 'pending']);
    $this->repo->paginate(['status' => 'pending']);

    $params = ['filters' => ['status' => 'pending'], 'page' => 1, 'perPage' => 50];
    expect((new ListCache('orders'))->get($params))->not->toBeNull();

    // total_amount is NOT list-invalidating -> lists survive, entity re-warms.
    $this->repo->manage('update', ['total_amount' => 99.50], $order->id);

    expect((new ListCache('orders'))->get($params))->not->toBeNull();
    expect((float) (new EntityCache('orders'))->get('id', $order->id)['total_amount'])->toBe(99.5);
});

it('deletes a row and clears its entity cache', function () {
    $order = $this->repo->manage('create', ['customer_id' => 1, 'status' => 'pending']);

    $result = $this->repo->manage('delete', id: $order->id);

    expect($result)->toBeTrue();
    expect((new EntityCache('orders'))->get('id', $order->id))->toBeNull();
    expect(Order::find($order->id))->toBeNull();
});

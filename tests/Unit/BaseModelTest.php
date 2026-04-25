<?php

use Hassam\DataAccessWrapper\Tests\Fixtures\Order;

it('exposes table name and fillable columns statically', function () {
    expect(Order::getTableName())->toBe('orders');
    expect(Order::getFillableColumns())->toContain('customer_id', 'status', 'total_amount');
});

it('lists real schema columns', function () {
    $columns = Order::getTableColumns();

    expect($columns)->toContain('id', 'customer_id', 'status', 'created_at', 'deleted_at');
});

it('strips appends before save', function () {
    $order = new Order();
    $order->customer_id = 1;
    $order->updated_by  = 1;
    $order->setAppends(['virtual']);

    $order->virtual = 'should-not-persist';
    $order->save();

    $fresh = Order::find($order->id);

    expect($fresh)->not->toBeNull();
    expect($fresh->getAttribute('virtual'))->toBeNull();
});

it('serializes dates as Y-m-d H:i:s', function () {
    $order = Order::create([
        'customer_id' => 1,
        'updated_by'  => 1,
    ]);

    $json = $order->toArray();

    expect($json['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

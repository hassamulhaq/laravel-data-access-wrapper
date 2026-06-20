<?php

use Hassam\DataAccessWrapper\BaseModel;
use Hassam\DataAccessWrapper\Tests\Fixtures\Order;
use Illuminate\Support\Facades\DB;

it('defaults cacheBy to the primary key and cacheTag to the table name', function () {
    $model = new class extends BaseModel
    {
        protected $table = 'widgets';
    };

    expect($model->cacheBy())->toBe(['id']);
    expect($model->cacheTag())->toBe('widgets');
});

it('lets a model override cacheBy', function () {
    expect((new Order)->cacheBy())->toBe(['id']);
});

it('exposes table name and fillable columns statically', function () {
    expect(Order::getTableName())->toBe('orders');
    expect(Order::getFillableColumns())->toContain('customer_id', 'status', 'total_amount');
});

it('lists real schema columns', function () {
    $columns = Order::getTableColumns();

    expect($columns)->toContain('id', 'customer_id', 'status', 'created_at', 'deleted_at');
});

it('strips appended attributes before save so they are not persisted as columns', function () {
    $order = new Order();
    $order->customer_id = 1;
    $order->updated_by = 1;
    $order->setAppends(['virtual']);

    // 'virtual' is appended AND set into the attribute bag — without the guard
    // Eloquent would try to INSERT a non-existent "virtual" column and fail.
    $order->virtual = 'should-not-persist';
    $order->save();

    $raw = DB::table('orders')->find($order->id);

    expect($raw)->not->toBeNull();
    expect(property_exists($raw, 'virtual'))->toBeFalse();
});

it('serializes dates as Y-m-d H:i:s', function () {
    $order = Order::create([
        'customer_id' => 1,
        'updated_by'  => 1,
    ]);

    $json = $order->toArray();

    expect($json['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

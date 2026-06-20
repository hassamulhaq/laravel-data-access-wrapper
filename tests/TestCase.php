<?php

namespace Hassam\DataAccessWrapper\Tests;

use Hassam\DataAccessWrapper\DataAccessWrapperServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchema();
    }

    protected function getPackageProviders($app): array
    {
        return [
            DataAccessWrapperServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Array store supports tags — required for the tag-based invalidation.
        $app['config']->set('cache.default', 'array');

        // Flat cache_versions map: key = cacheTag (table name).
        $app['config']->set('cache_versions', [
            'orders' => 'v1',
            'order_items' => 'v1',
        ]);
    }

    protected function setUpSchema(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('status')->default('pending');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}

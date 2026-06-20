<?php

namespace Hassam\DataAccessWrapper\Tests\Fixtures;

use Hassam\DataAccessWrapper\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends BaseModel
{
    use SoftDeletes;

    protected $table = 'orders';

    protected $fillable = [
        'customer_id', 'status', 'total_amount',
        'created_by', 'updated_by',
    ];

    /**
     * Cached by primary key only — id is the sole unique column here.
     */
    public function cacheBy(): array
    {
        return ['id'];
    }

    /**
     * status is filterable, so changing it must invalidate list caches.
     * total_amount edits only touch the entity cache.
     */
    protected array $listInvalidatingColumns = ['status', 'customer_id'];

    /**
     * A computed (appended) attribute — never a real column. Used to prove the
     * save() guard strips appended attributes before they reach an INSERT.
     */
    public function getVirtualAttribute(): string
    {
        return 'computed';
    }
}

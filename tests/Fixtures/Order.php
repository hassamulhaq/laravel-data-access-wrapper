<?php

namespace Hassam\DataAccessWrapper\Tests\Fixtures;

use Hassam\DataAccessWrapper\BaseModel;
use Hassam\DataAccessWrapper\Traits\Cacheable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends BaseModel
{
    use Cacheable, SoftDeletes;

    protected $table = 'orders';

    protected $fillable = [
        'customer_id', 'status', 'total_amount',
        'created_by', 'updated_by',
    ];

    public function cacheBy(): array
    {
        return [
            ['id'],
            'by_customer' => ['customer_id'],
        ];
    }

    public function cacheTagsForFlush(): array
    {
        $tags = [
            "order:{$this->id}",
            "customer:{$this->customer_id}",
        ];

        if ($this->wasChanged('customer_id')) {
            $tags[] = "customer:{$this->getOriginal('customer_id')}";
        }

        return $tags;
    }
}

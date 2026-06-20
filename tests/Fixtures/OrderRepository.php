<?php

namespace Hassam\DataAccessWrapper\Tests\Fixtures;

use Hassam\DataAccessWrapper\BaseRepository;
use Illuminate\Database\Eloquent\Builder;

class OrderRepository extends BaseRepository
{
    protected function model(): string
    {
        return Order::class;
    }

    protected function cacheTag(): string
    {
        return 'orders';
    }

    protected function eagerLoads(): array
    {
        return [];
    }

    protected function buildQuery(array $filters = []): Builder
    {
        return Order::query()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->orderBy('id');
    }
}

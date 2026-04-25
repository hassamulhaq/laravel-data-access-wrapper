<?php

namespace Hassam\DataAccessWrapper;

use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class BaseCache
{
    private const CACHE_VERSION = 'prod:2';

    /** @var string[] */
    protected array $tags = [];

    public function __construct(
        public string $keyPrefix,
        public string $modelVersion,
        array $tags = []
    ) {
        $this->tags = array_values(array_unique(array_filter($tags, 'strlen')));
    }

    /**
     * Return a NEW BaseCache that scopes every op to the given tag(s).
     * Original instance is untouched, so chaining is side-effect free:
     *
     *   $base = Order::cache();
     *   $byCustomer = $base->tags("customer:{$id}");   // new instance
     *   $byCustomer->flush();                          // doesn't affect $base
     *
     * @param  string|string[] $tags
     */
    public function tags(string|array $tags): static
    {
        $merged = array_values(array_unique(array_merge(
            $this->tags,
            is_array($tags) ? $tags : [$tags],
        )));

        $clone = clone $this;
        $clone->tags = $merged;

        return $clone;
    }

    public function hasTags(): bool
    {
        return $this->tags !== [];
    }

    /** @return string[] */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function remember($key, Closure $callback, $byPassCache = false, $ttl = 15768000)
    {
        if (is_array($key)) {
            foreach ($key as $prefix => $value) {
                $key = $prefix .'-'. $value;
            }
        }

        if (! $byPassCache) {
            $value = $this->get($key);
            if (! is_null($value)) {
                return $value;
            }
        }

        $value = $callback();
        if (! is_null($value)) {
            $this->put($key, $value, $ttl);
        }

        return $value;
    }

    public function put($key, $data, $ttl = 15768000): bool
    {
        return $this->store()->put($this->getFinalPrefix().$key, $data, $ttl);
    }

    public function putWithoutPrefix($key, $data = null, $ttl = 15768000): bool
    {
        return Cache::put($key, $data, $ttl);
    }

    public function get($key)
    {
        return $this->store()->get($this->getFinalPrefix().$key);
    }

    public function getWithoutPrefix($key)
    {
        return Cache::get($key);
    }

    public function pull($key)
    {
        return $this->store()->pull($this->getFinalPrefix().$key);
    }

    public function pullWithoutPrefix($key)
    {
        return Cache::pull($key);
    }

    public function forget($key): bool
    {
        return $this->store()->forget($this->getFinalPrefix().$key);
    }

    public function forgetWithoutPrefix($key): bool
    {
        return Cache::forget($key);
    }

    /**
     * Wipe every entry recorded under the current tag set.
     *
     * Requires at least one tag. Calling flush() without tags is rejected
     * to prevent accidentally clearing the entire application cache.
     * Tag-aware stores only: Redis, Memcached, array, dynamodb.
     */
    public function flush(): bool
    {
        if (! $this->hasTags()) {
            throw new BadMethodCallException(
                'BaseCache::flush() requires at least one tag. Use forget($key) for keyed deletes.'
            );
        }

        return Cache::tags($this->tags)->flush();
    }

    public function getKey($key): string
    {
        return $this->getFinalPrefix().$key;
    }

    /**
     * Resolve the underlying cache repository, applying tags when present.
     */
    protected function store(): Repository
    {
        return $this->hasTags()
            ? Cache::tags($this->tags)
            : Cache::store();
    }

    private function getFinalPrefix(): string
    {
        return self::CACHE_VERSION.$this->modelVersion.$this->keyPrefix;
    }
}

<?php

namespace Hassam\DataAccessWrapper\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * EntityCache
 *
 * Stores individual model rows in the cache, scoped per:
 *   - tenant   (optional stancl/tenancy — tenant ID prefix, "global" otherwise)
 *   - version  (config/cache_versions.php — bump to globally invalidate)
 *   - column   (the cacheBy() column used as the lookup key)
 *   - value    (the column value, e.g. id=1, slug=cheese-burger)
 *
 * Key format:
 *   {tenant_id}:{model_tag}:{version}:{column}:{value}
 *
 * Example:
 *   tenant_42:products:v2:id:1
 *   global:products:v1:slug:cheese-burger
 *
 * Requires a cache store that supports tags (redis, memcached, or the array
 * store used in tests). File and database stores do not support tags.
 */
class EntityCache
{
    protected string $tag;

    protected string $version;

    protected int $ttl;

    public function __construct(string $modelTag, int $ttl = 3600)
    {
        $this->tag = $modelTag;
        $this->version = $this->resolveVersion($modelTag);
        $this->ttl = $ttl;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Retrieve a cached entity by any cacheBy() column.
     *
     * @param  string  $column  e.g. 'id', 'slug'
     * @param  int|string  $value  e.g. 1, 'cheese-burger'
     */
    public function get(string $column, int|string $value): ?array
    {
        try {
            return Cache::tags($this->tags())->get($this->key($column, $value));
        } catch (\Throwable $e) {
            Log::warning("[EntityCache] get failed: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Store a model instance under ALL its cacheBy() columns.
     *
     * If the model defines cacheBy() = ['id', 'slug'], the entity is stored
     * under two keys — one per column — so lookups by either column hit cache.
     */
    public function put(Model $model): void
    {
        $data = $model->toArray();
        $columns = $this->resolveCacheByColumns($model);

        foreach ($columns as $column) {
            $value = $model->{$column} ?? null;

            if ($value === null) {
                continue;
            }

            try {
                Cache::tags($this->tags())->put(
                    $this->key($column, $value),
                    $data,
                    now()->addSeconds($this->ttl)
                );
            } catch (\Throwable $e) {
                Log::warning("[EntityCache] put failed for {$column}={$value}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Forget a cached entity by a specific column + value.
     */
    public function forget(string $column, int|string $value): void
    {
        try {
            Cache::tags($this->tags())->forget($this->key($column, $value));
        } catch (\Throwable $e) {
            Log::warning("[EntityCache] forget failed: {$e->getMessage()}");
        }
    }

    /**
     * Forget all cache entries for a model instance (all its cacheBy columns).
     */
    public function forgetModel(Model $model): void
    {
        $columns = $this->resolveCacheByColumns($model);

        foreach ($columns as $column) {
            $value = $model->{$column} ?? null;
            if ($value !== null) {
                $this->forget($column, $value);
            }
        }
    }

    /**
     * Forget many entities by a single column.
     *
     * @param  array<int|string>  $values
     */
    public function forgetMany(string $column, array $values): void
    {
        foreach ($values as $value) {
            $this->forget($column, $value);
        }
    }

    /**
     * Flush ALL cache entries for this model tag (across current tenant).
     * Use sparingly — prefer forgetModel() for surgical invalidation.
     */
    public function flush(): void
    {
        try {
            Cache::tags($this->tags())->flush();
        } catch (\Throwable $e) {
            Log::warning("[EntityCache] flush failed: {$e->getMessage()}");
        }
    }

    // ── Key & Tag Building ────────────────────────────────────────────────────

    /**
     * Build a fully-qualified cache key.
     *
     * Format: {tenant}:{tag}:{version}:{column}:{value}
     */
    public function key(string $column, int|string $value): string
    {
        return implode(':', [
            $this->tenantPrefix(),
            $this->tag,
            $this->version,
            $column,
            $value,
        ]);
    }

    /**
     * Cache tags for this model + tenant combination.
     *
     * NOTE: a single model-scoped tag only. Do NOT add a shared ":entities"
     * tag here — flush() flushes the UNION of the tag set, so a shared tag
     * would let one model's flush() wipe every model.
     */
    protected function tags(): array
    {
        return [
            $this->tenantPrefix().':'.$this->tag,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function tenantPrefix(): string
    {
        try {
            $tenantId = function_exists('tenant') ? tenant('id') : null;

            return $tenantId ? "tenant_{$tenantId}" : 'global';
        } catch (\Throwable) {
            return 'global';
        }
    }

    protected function resolveVersion(string $modelTag): string
    {
        return config("cache_versions.{$modelTag}", 'v1');
    }

    /**
     * @return array<string>
     */
    protected function resolveCacheByColumns(Model $model): array
    {
        if (method_exists($model, 'cacheBy')) {
            return $model->cacheBy();
        }

        // Fallback: use primary key only
        return [$model->getKeyName()];
    }
}

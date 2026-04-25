<?php

namespace Hassam\DataAccessWrapper\Traits;

use BackedEnum;
use Hassam\DataAccessWrapper\BaseCache;
use Illuminate\Database\Eloquent\Model;

trait Cacheable
{
    /**
     * Per-row cache keys to invalidate when this row changes.
     *
     * Each entry is a list of model attribute names whose values get
     * concatenated into a key. Optional string array key becomes a key prefix.
     *
     * @return array<int|string, string[]>
     */
    abstract public function cacheBy(): array;

    public static function bootCacheable(): void
    {
        // `saved` covers both `created` and `updated`, so we don't register
        // `updated` separately (avoids a double-flush per update).
        static::saved(static fn (Model $model) => $model->deleteCache());
        static::deleted(static fn (Model $model) => $model->deleteCache());

        // SoftDeletes-aware events: only fire on models using SoftDeletes,
        // but registering them is harmless on models without it.
        if (method_exists(static::class, 'restored')) {
            static::restored(static fn (Model $model) => $model->deleteCache());
        }
        if (method_exists(static::class, 'forceDeleted')) {
            static::forceDeleted(static fn (Model $model) => $model->deleteCache());
        }
    }

    public static function cache(): BaseCache
    {
        $tableName = static::getTableName();

        return new BaseCache(
            "-{$tableName}-",
            (string) config("cache-versions.version.{$tableName}", 'v1')
        );
    }

    /**
     * Optional extra static key flushed on every change.
     */
    public function cacheKey(): string
    {
        return '';
    }

    /**
     * Tags to flush on save/delete/restore/forceDelete.
     * Override on the model to enable automatic tag invalidation.
     *
     * Examples:
     *
     *     return [
     *         "order:{$this->id}",
     *         "customer:{$this->customer_id}",
     *     ];
     *
     * If an attribute changed, include the original value too so the
     * old tag's cache also gets wiped:
     *
     *     if ($this->wasChanged('customer_id')) {
     *         $tags[] = "customer:{$this->getOriginal('customer_id')}";
     *     }
     *
     * @return string[]
     */
    public function cacheTagsForFlush(): array
    {
        return [];
    }

    public static function getTableName(): string
    {
        return (new static)->getTable();
    }

    public function deleteCache(): void
    {
        $cacheBy = $this->cacheBy();

        if ($cacheBy !== [] && is_string(reset($cacheBy))) {
            $cacheBy = [$cacheBy];
        }

        foreach ($cacheBy as $prefix => $attributes) {
            $prefix = is_string($prefix) ? "{$prefix}-" : '';

            // Flush key built from CURRENT attribute values.
            static::cache()->forget($prefix . $this->buildCacheKey($attributes, useOriginal: false));

            // If any of the attributes changed, also flush key built from
            // ORIGINAL values so stale entries don't leak.
            if ($this->cacheAttributesChanged($attributes)) {
                static::cache()->forget($prefix . $this->buildCacheKey($attributes, useOriginal: true));
            }
        }

        if ($this->cacheKey() !== '') {
            static::cache()->forget($this->cacheKey());
        }

        // Auto-flush tag-scoped caches the model opted into.
        foreach ($this->cacheTagsForFlush() as $tag) {
            if ($tag !== '' && $tag !== null) {
                static::cache()->tags($tag)->flush();
            }
        }
    }

    /**
     * @param  string[] $attributes
     */
    protected function buildCacheKey(array $attributes, bool $useOriginal): string
    {
        return implode('-', array_map(function (string $attribute) use ($useOriginal) {
            $value = $useOriginal ? $this->getOriginal($attribute) : $this->{$attribute};

            return $value instanceof BackedEnum ? $value->value : $value;
        }, $attributes));
    }

    /**
     * @param  string[] $attributes
     */
    protected function cacheAttributesChanged(array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            if ($this->wasChanged($attribute)) {
                return true;
            }
        }

        return false;
    }
}

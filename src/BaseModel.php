<?php

namespace Hassam\DataAccessWrapper;

use DateTimeInterface;
use Hassam\DataAccessWrapper\Cache\EntityCache;
use Hassam\DataAccessWrapper\Observers\ModelCacheObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * BaseModel
 *
 * Abstract base for all cacheable Eloquent models.
 *
 * Every model that extends this class:
 *
 *   1. Gets cacheBy() — defaults to the primary key. Override to declare
 *      additional UNIQUE columns to cache the row under.
 *   2. Automatically registers ModelCacheObserver — handles invalidation on
 *      created / updated / deleted / restored without any manual wiring.
 *   3. Gets a cacheTag() method — returns the table name used as the cache
 *      tag and the key in config/cache_versions.php.
 *   4. Inherits table-introspection helpers (getTableName / getTableColumns /
 *      getFillableColumns) plus an append-stripping save() guard.
 *
 * ── cacheBy() contract ────────────────────────────────────────────────────────
 *
 *   Return a FLAT array of column names whose values are used as cache keys.
 *   The entity is stored once per column, so a lookup by ANY of those columns
 *   hits the cache.
 *
 *   Every column MUST be uniquely indexed. A non-unique column (e.g. branch_id)
 *   would make every row overwrite the previous one under the same key.
 *
 *   Example — Product: ['id', 'slug']
 *
 * ── Version-based invalidation ────────────────────────────────────────────────
 *
 *   config/cache_versions.php controls the version segment in every key.
 *   Bumping 'products' from 'v1' to 'v2' instantly makes all product cache
 *   keys unreachable — old keys expire naturally via TTL.
 *
 * ── Tenant isolation ─────────────────────────────────────────────────────────
 *
 *   When stancl/tenancy is installed every cache key is prefixed with the
 *   current tenant ID; otherwise keys are prefixed with "global".
 */
abstract class BaseModel extends Model
{
    /**
     * Columns this model is cached under. Defaults to the primary key.
     *
     * Override to add more UNIQUE lookup columns:
     *   public function cacheBy(): array { return ['id', 'slug']; }
     *
     * @return array<string>
     */
    public function cacheBy(): array
    {
        return [$this->getKeyName()];
    }

    /**
     * The cache tag and cache_versions.php key for this model.
     * Defaults to the model's table name (e.g. "products", "tables").
     * Override if the table name differs from the desired tag.
     */
    public function cacheTag(): string
    {
        return $this->getTable();
    }

    /**
     * Boot — auto-register the cache observer on every model that extends
     * BaseModel. No manual Observer::register() calls needed anywhere.
     */
    protected static function booted(): void
    {
        static::observe(ModelCacheObserver::class);
    }

    /**
     * Convenience: get a pre-configured EntityCache instance for this model.
     *
     * Example:
     *   $product->entityCache()->forgetModel($product);
     */
    public function entityCache(): EntityCache
    {
        return new EntityCache($this->cacheTag());
    }

    // ── Persistence guards ────────────────────────────────────────────────────

    /**
     * Drop appended (accessor-driven) attributes before persistence so they
     * don't accidentally end up written as columns.
     */
    public function save(array $options = []): bool
    {
        foreach ($this->getArrayableAppends() as $append) {
            $this->offsetUnset($append);
        }

        return parent::save($options);
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    // ── Table introspection helpers ───────────────────────────────────────────

    public static function getTableName(): string
    {
        return (new static)->getTable();
    }

    /**
     * Real column names on the underlying table.
     *
     * @return array<string>
     */
    public static function getTableColumns(?string $table = null): array
    {
        return Schema::getColumnListing($table ?: static::getTableName());
    }

    /**
     * Columns declared `$fillable` on the model.
     *
     * @return array<string>
     */
    public static function getFillableColumns(): array
    {
        return (new static)->getFillable();
    }
}

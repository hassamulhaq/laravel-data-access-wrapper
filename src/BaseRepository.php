<?php

namespace Hassam\DataAccessWrapper;

use Hassam\DataAccessWrapper\Cache\EntityCache;
use Hassam\DataAccessWrapper\Cache\ListCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * BaseRepository
 *
 * Combines EntityCache and ListCache into a clean read/write interface.
 *
 * Child repositories must implement:
 *   - model()       → fully qualified model class string
 *   - cacheTag()    → matches a key in config/cache_versions.php
 *   - eagerLoads()  → relationships to load with each entity
 *   - buildQuery()  → base Eloquent query with filters applied
 *
 * All writes go through manage() which keeps the cache in sync via the
 * model's ModelCacheObserver.
 */
abstract class BaseRepository
{
    protected EntityCache $entityCache;

    protected ListCache $listCache;

    // Entity TTL: 1 hour. List TTL: 10 minutes (shorter — reflects ordering/counts).
    protected int $entityTtl = 3600;

    protected int $listTtl = 600;

    public function __construct()
    {
        $this->entityCache = new EntityCache($this->cacheTag(), $this->entityTtl);
        $this->listCache = new ListCache($this->cacheTag(), $this->listTtl);
    }

    // ── Abstract contract ─────────────────────────────────────────────────────

    /** Fully qualified model class. e.g. App\Models\Product::class */
    abstract protected function model(): string;

    /** Must match a key in config/cache_versions.php. e.g. 'products' */
    abstract protected function cacheTag(): string;

    /** Relationships to eager-load when caching entities. e.g. ['category'] */
    abstract protected function eagerLoads(): array;

    /** Base query with filters applied. Never call ->get() here. */
    abstract protected function buildQuery(array $filters = []): Builder;

    // ── Reads ─────────────────────────────────────────────────────────────────

    /**
     * Find a single entity by any column defined in its cacheBy().
     *
     * @param  string  $column  The column to look up. Must be in the model's cacheBy().
     * @param  int|string  $value  The value to match.
     */
    public function findBy(string $column, int|string $value): ?array
    {
        // 1. Check entity cache first
        $cached = $this->entityCache->get($column, $value);
        if ($cached !== null) {
            return $cached;
        }

        // 2. Miss — fetch from DB
        $model = ($this->model())::with($this->eagerLoads())
            ->where($column, $value)
            ->first();

        if (! $model) {
            return null;
        }

        // 3. Store under ALL cacheBy() columns for future lookups
        $this->entityCache->put($model);

        return $model->toArray();
    }

    /**
     * Shorthand for findBy('id', $id).
     */
    public function findById(int $id): ?array
    {
        return $this->findBy('id', $id);
    }

    /**
     * Paginated list using the ID-list strategy.
     *
     * Flow:
     *   1. Check list cache for page IDs.
     *   2. If miss, run SELECT id only query, cache the IDs.
     *   3. Hydrate each ID from entity cache (batch-fetch DB misses in one query).
     *   4. Return a LengthAwarePaginator with hydrated entities.
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        $params = compact('filters', 'page', 'perPage');

        // 1. Try list cache
        $cached = $this->listCache->get($params);

        if ($cached !== null) {
            $ids = $cached['ids'];
            $total = $cached['total'];
        } else {
            // 2. Fetch IDs only from DB (cheap — no joins on full rows)
            $result = $this->buildQuery($filters)
                ->paginate($perPage, ['id'], 'page', $page);

            $ids = $result->pluck('id')->toArray();
            $total = $result->total();

            $this->listCache->put($params, $ids, $total);
        }

        // 3. Hydrate — cache hits + one batch DB query for misses
        $entities = $this->hydrateIds($ids);

        // 4. Return standard paginator
        return new LengthAwarePaginator(
            items: $entities,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    // ── Writes — single entry point ───────────────────────────────────────────

    /**
     * Single method for all write operations.
     * Cache invalidation is handled automatically by ModelCacheObserver,
     * which fires on Eloquent events triggered here.
     *
     * @param  string  $action  'create' | 'update' | 'delete'
     * @param  array  $data  Validated data for create/update
     * @param  int|null  $id  Required for update/delete
     */
    public function manage(string $action, array $data = [], ?int $id = null): mixed
    {
        return match ($action) {
            'create' => $this->performCreate($data),
            'update' => $this->performUpdate($id, $data),
            'delete' => $this->performDelete($id),
            default => throw new \InvalidArgumentException("Unknown action [{$action}]. Expected: create, update, delete."),
        };
    }

    // ── Write implementations ─────────────────────────────────────────────────

    protected function performCreate(array $data): Model
    {
        // ModelCacheObserver::created() handles cache automatically
        $model = ($this->model())::create($data);
        $model->load($this->eagerLoads());

        return $model;
    }

    protected function performUpdate(int $id, array $data): Model
    {
        $model = ($this->model())::findOrFail($id);
        $model->update($data);
        $model->load($this->eagerLoads());

        // ModelCacheObserver::updated() handles cache automatically
        return $model;
    }

    protected function performDelete(int $id): bool
    {
        $model = ($this->model())::findOrFail($id);
        $model->delete();

        // ModelCacheObserver::deleted() handles cache automatically
        return true;
    }

    // ── Hydration ─────────────────────────────────────────────────────────────

    /**
     * Given a list of IDs, return fully hydrated entity arrays.
     *
     * - Cache hits:  returned immediately from the cache.
     * - Cache misses: fetched from DB in ONE batch query (never N+1).
     * - Re-cached:  misses are stored back to entity cache after fetch.
     * - Order:      result order matches the input $ids order exactly.
     */
    protected function hydrateIds(array $ids): array
    {
        $results = [];
        $missing = [];

        foreach ($ids as $id) {
            $cached = $this->entityCache->get('id', $id);
            if ($cached !== null) {
                $results[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        if (! empty($missing)) {
            ($this->model())::with($this->eagerLoads())
                ->whereIn('id', $missing)
                ->get()
                ->each(function (Model $model) use (&$results) {
                    $this->entityCache->put($model);
                    $results[$model->getKey()] = $model->toArray();
                });
        }

        // Preserve original list order from the list cache
        return array_values(
            array_filter(
                array_map(fn ($id) => $results[$id] ?? null, $ids)
            )
        );
    }

    // ── Manual cache control ──────────────────────────────────────────────────

    /**
     * Manually invalidate a single entity by ID.
     * Prefer letting the observer handle this — use only when needed outside
     * the normal Eloquent lifecycle (e.g. bulk DB updates via Query Builder).
     */
    public function invalidate(int $id): void
    {
        $model = ($this->model())::find($id);
        if ($model) {
            $this->entityCache->forgetModel($model);
        }
    }

    /**
     * Flush all list caches for this model. Entity caches are untouched.
     */
    public function invalidateLists(): void
    {
        $this->listCache->flush();
    }

    /**
     * Full flush — entities + lists. Use after bulk imports or migrations.
     */
    public function invalidateAll(): void
    {
        $this->entityCache->flush();
        $this->listCache->flush();
    }
}

<?php

namespace Hassam\DataAccessWrapper\Observers;

use Hassam\DataAccessWrapper\BaseModel;
use Hassam\DataAccessWrapper\Cache\EntityCache;
use Hassam\DataAccessWrapper\Cache\ListCache;
use Illuminate\Database\Eloquent\Model;

/**
 * ModelCacheObserver
 *
 * Automatically wired to every model that extends BaseModel via booted().
 *
 * Invalidation strategy per event:
 *
 *   created  → pre-warm entity with new data + flush lists
 *              (new row changes page totals and ordering)
 *
 *   updated  → flush entity then re-warm with fresh data + conditionally
 *              flush lists only if a filterable/sortable column changed
 *
 *   deleted  → flush entity + flush lists
 *              (row is gone; any list containing its ID is now invalid)
 *
 *   restored → same as created
 *              (soft-deleted row is back; lists need to reflect it)
 *
 * List flush on update is conditional:
 *   If you update only 'notes' or 'description' — columns not used in filters
 *   or sort — lists do NOT need flushing. Only the entity changes. Define
 *   $listInvalidatingColumns on your model to control this.
 *
 *   Example on Product model:
 *     protected array $listInvalidatingColumns = ['name', 'active', 'category_id'];
 *
 *   If $listInvalidatingColumns is not defined, lists are always flushed on
 *   update (safe default).
 */
class ModelCacheObserver
{
    public function created(Model $model): void
    {
        if (! $model instanceof BaseModel) {
            return;
        }

        $entityCache = new EntityCache($model->cacheTag());
        $listCache = new ListCache($model->cacheTag());

        // Pre-warm entity cache with the new row
        $entityCache->put($model);

        // New row affects list totals and ordering
        $listCache->flush();
    }

    public function updated(Model $model): void
    {
        if (! $model instanceof BaseModel) {
            return;
        }

        $entityCache = new EntityCache($model->cacheTag());
        $listCache = new ListCache($model->cacheTag());

        // Flush stale entity first
        $entityCache->forgetModel($model);

        // Re-warm immediately so next request doesn't hit DB
        $entityCache->put($model);

        // Only flush lists if a column that affects filtering/sorting changed
        if ($this->shouldFlushLists($model)) {
            $listCache->flush();
        }
    }

    public function deleted(Model $model): void
    {
        if (! $model instanceof BaseModel) {
            return;
        }

        $entityCache = new EntityCache($model->cacheTag());
        $listCache = new ListCache($model->cacheTag());

        // Row is gone — flush entity and all lists that may reference it
        $entityCache->forgetModel($model);
        $listCache->flush();
    }

    public function restored(Model $model): void
    {
        // Treat like a create — row is back, lists need updating
        $this->created($model);
    }

    public function forceDeleted(Model $model): void
    {
        $this->deleted($model);
    }

    // ── Conditional list flush ────────────────────────────────────────────────

    /**
     * Determine whether the updated model's dirty columns warrant flushing the
     * list cache.
     *
     * If the model defines $listInvalidatingColumns, only flush when one of
     * those columns was changed. Otherwise flush always (safe default).
     */
    protected function shouldFlushLists(Model $model): bool
    {
        $sensitive = $this->listInvalidatingColumns($model);

        if ($sensitive === null) {
            return true; // safe default — no declaration means always flush
        }

        $changed = array_keys($model->getChanges());

        return count(array_intersect($changed, $sensitive)) > 0;
    }

    /**
     * Read the model's protected $listInvalidatingColumns. A plain
     * $model->listInvalidatingColumns would hit Eloquent's __get and return
     * null, so bind a closure to the model to read the real property value.
     *
     * @return array<string>|null  null when the model does not declare it
     */
    protected function listInvalidatingColumns(Model $model): ?array
    {
        if (! property_exists($model, 'listInvalidatingColumns')) {
            return null;
        }

        $value = (fn () => $this->listInvalidatingColumns ?? null)->call($model);

        return is_array($value) ? $value : null;
    }
}

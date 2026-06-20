<?php

namespace Hassam\DataAccessWrapper\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ListCache
 *
 * Stores paginated/filtered result sets as ID arrays only.
 * Entity data is never stored here — only primary key values.
 *
 * Key format:
 *   {tenant_id}:{model_tag}:{version}:list:{md5_of_params}
 *
 * Example:
 *   tenant_42:products:v1:list:a3f1c9d8e2b7...
 *
 * List caches have a shorter TTL than entity caches by default (10 min vs 1 hr)
 * because they reflect ordering, filtering, and pagination which change more
 * frequently than the entity data itself.
 *
 * When to flush list cache:
 *   - A new row is created (changes totals and may affect ordering)
 *   - A row is deleted (ID no longer valid in any list)
 *   - A column used for filtering/sorting is updated
 *
 * When NOT to flush list cache:
 *   - A non-filterable column is updated (e.g. notes, description)
 *     → Only flush the entity, leave lists intact
 */
class ListCache
{
    protected string $tag;

    protected string $version;

    protected int $ttl;

    public function __construct(string $modelTag, int $ttl = 600)
    {
        $this->tag = $modelTag;
        $this->version = config("cache_versions.{$modelTag}", 'v1');
        $this->ttl = $ttl;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Retrieve a cached ID list.
     *
     * Returns null on miss (never returns an empty array on miss —
     * empty arrays can be legitimate cached results).
     *
     * @return array{ids: array<int|string>, total: int}|null
     */
    public function get(array $params): ?array
    {
        try {
            return Cache::tags($this->tags())->get($this->key($params));
        } catch (\Throwable $e) {
            Log::warning("[ListCache] get failed: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Store a list of IDs with its total count.
     *
     * @param  array<int|string>  $ids
     */
    public function put(array $params, array $ids, int $total): void
    {
        try {
            Cache::tags($this->tags())->put(
                $this->key($params),
                ['ids' => $ids, 'total' => $total],
                now()->addSeconds($this->ttl)
            );
        } catch (\Throwable $e) {
            Log::warning("[ListCache] put failed: {$e->getMessage()}");
        }
    }

    /**
     * Flush ALL list caches for this model + tenant.
     * Entity caches are NOT affected.
     */
    public function flush(): void
    {
        try {
            Cache::tags($this->tags())->flush();
        } catch (\Throwable $e) {
            Log::warning("[ListCache] flush failed: {$e->getMessage()}");
        }
    }

    // ── Key & Tag Building ────────────────────────────────────────────────────

    protected function key(array $params): string
    {
        return implode(':', [
            $this->tenantPrefix(),
            $this->tag,
            $this->version,
            'list',
            md5(serialize($params)),
        ]);
    }

    /**
     * Single model-scoped list tag. No shared ":lists" tag — flush() flushes
     * the UNION of the tag set, so a shared tag would let one model's list
     * flush wipe every model's lists for the tenant.
     */
    protected function tags(): array
    {
        return [
            $this->tenantPrefix().':'.$this->tag.':lists',
        ];
    }

    protected function tenantPrefix(): string
    {
        try {
            $tenantId = function_exists('tenant') ? tenant('id') : null;

            return $tenantId ? "tenant_{$tenantId}" : 'global';
        } catch (\Throwable) {
            return 'global';
        }
    }
}

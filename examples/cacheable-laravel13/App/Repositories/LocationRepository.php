<?php

namespace App\Repositories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;

class LocationRepository
{
    public const string CACHE_KEY_BY_BRANCH = 'by_branch';

    public const string CACHE_KEY_ACTIVE_BY_BRANCH = 'active_by_branch';

    public const string CACHE_KEY_BY_SLUG = 'slug';

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    public static function manage(array $data, ?int $id = null): ?Location
    {
        if (! isset($data['updated_by'])) {
            return null;
        }
        $columns  = Location::getFillableColumns();
        $location = $id ? Location::query()->find($id) : null;
        if ($id && ! $location) {
            return null;
        }
        if (! $location) {
            $location = new Location;
            $location->created_by = $data['updated_by'];
        }
        foreach ($data as $key => $value) {
            if (in_array($key, $columns, true)) {
                $location->{$key} = $value;
            }
        }
        $location->save();

        return $location;
    }

    /*
    |--------------------------------------------------------------------------
    | Reads — cache attribute arrays, rehydrate via Eloquent helpers.
    | Caching Eloquent objects breaks under stancl/tenancy cache bootstrapper.
    |--------------------------------------------------------------------------
    */

    public static function getById(int $id): ?Location
    {
        $attrs = Location::cache()->remember(
            $id,
            fn () => Location::query()->find($id)?->getAttributes(),
        );

        return self::makeOne($attrs);
    }

    public static function getByIdWithTrashed(int $id): ?Location
    {
        $attrs = Location::cache()->remember(
            "trashed-{$id}",
            fn () => Location::withTrashed()->find($id)?->getAttributes(),
        );

        return self::makeOne($attrs);
    }

    public static function getBySlug(string $slug, int $branchId): ?Location
    {
        $key = self::CACHE_KEY_BY_SLUG.'-'.$slug.'-'.$branchId;

        $attrs = Location::cache()->remember($key, function () use ($slug, $branchId) {
            return Location::query()
                ->where('slug', $slug)
                ->where('branch_id', $branchId)
                ->first()?->getAttributes();
        });

        return self::makeOne($attrs);
    }

    /**
     * @return array<int, Location>
     */
    public static function getAll(): array
    {
        $rows = Location::cache()
            ->tags('locations')
            ->remember('all', fn () => Location::query()
                ->orderByDesc('created_at')
                ->get()->map->getAttributes()->all());

        return self::makeMany($rows);
    }

    /**
     * @return array<int, Location>
     */
    public static function getByBranch(int $branchId): array
    {
        $key = self::CACHE_KEY_BY_BRANCH.'-'.$branchId;

        $rows = Location::cache()
            ->tags("branch:{$branchId}")
            ->remember($key, fn () => Location::query()
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->get()->map->getAttributes()->all());

        return self::makeMany($rows);
    }

    /**
     * @return array<int, Location>
     */
    public static function getActiveByBranch(int $branchId): array
    {
        $key = self::CACHE_KEY_ACTIVE_BY_BRANCH.'-'.$branchId;

        $rows = Location::cache()
            ->tags("branch:{$branchId}")
            ->remember($key, fn () => Location::query()
                ->active()
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->get()->map->getAttributes()->all());

        return self::makeMany($rows);
    }

    /**
     * Eager relations don't survive serialization across tenancy. Skip cache.
     */
    public static function getWithTables(int $id): ?Location
    {
        return Location::with('tables')->find($id);
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    public static function delete(int $id, int $deletedBy): bool
    {
        $location = Location::query()->find($id);
        if (! $location) {
            return false;
        }
        $location->updated_by = $deletedBy;
        $location->save();

        return (bool) $location->delete();
    }

    public static function restore(int $id, int $restoredBy): ?Location
    {
        $location = Location::withTrashed()->find($id);
        if (! $location || ! $location->trashed()) {
            return null;
        }
        $location->updated_by = $restoredBy;
        $location->restore();

        return $location;
    }

    /*
    |--------------------------------------------------------------------------
    | Hydration
    |--------------------------------------------------------------------------
    */

    private static function makeOne(?array $attrs): ?Location
    {
        return $attrs ? Location::hydrate([$attrs])->first() : null;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return array<int, Location>
     */
    private static function makeMany(?array $rows): array
    {
        if (! $rows) {
            return [];
        }

        /** @var Collection<int, Location> $models */
        $models = Location::hydrate($rows);

        return $models->keyBy->getKey()->all();
    }
}

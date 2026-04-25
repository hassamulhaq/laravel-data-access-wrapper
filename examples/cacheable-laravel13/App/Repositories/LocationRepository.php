<?php

namespace App\Repositories;

use App\Models\Location;

class LocationRepository
{
    public const string CACHE_KEY_BY_BRANCH = 'by_branch';

    public const string CACHE_KEY_ACTIVE_BY_BRANCH = 'active_by_branch';

    public const string CACHE_KEY_BY_SLUG = 'slug';

    /**
     * Create or update. Returns the saved model, or null if:
     *   - $data missing updated_by, or
     *   - $id provided but no row exists.
     */
    public static function manage(array $data, ?int $id = null): ?Location
    {
        if (! isset($data['updated_by'])) {
            return null;
        }
        $columns = Location::getFillableColumns();
        $location = $id ? self::getById($id) : null;
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
    | Reads
    |--------------------------------------------------------------------------
    */

    public static function getById(int $id): ?Location
    {
        return Location::cache()->remember($id, function () use ($id) {
            return Location::query()->find($id);
        });
    }

    public static function getByIdWithTrashed(int $id): ?Location
    {
        return Location::cache()->remember("trashed-{$id}", function () use ($id) {
            return Location::withTrashed()->find($id);
        });
    }

    /**
     * @return array<int, Location>
     */
    public static function getAll(): array
    {
        return Location::cache()
            ->tags('locations')
            ->remember('all', function () {
                return Location::query()
                    ->orderByDesc('created_at')
                    ->get()
                    ->keyBy('id')
                    ->all();
            });
    }

    public static function getBySlug(string $slug, int $branchId): ?Location
    {
        $key = self::CACHE_KEY_BY_SLUG.'-'.$slug.'-'.$branchId;

        return Location::cache()->remember($key, function () use ($slug, $branchId) {
            return Location::query()
                ->where('slug', $slug)
                ->where('branch_id', $branchId)
                ->first();
        });
    }

    /**
     * @return array<int, Location>
     */
    public static function getByBranch(int $branchId): array
    {
        $key = self::CACHE_KEY_BY_BRANCH.'-'.$branchId;

        return Location::cache()
            ->tags("branch:{$branchId}")
            ->remember($key, function () use ($branchId) {
                return Location::query()
                    ->where('branch_id', $branchId)
                    ->orderBy('name')
                    ->get()
                    ->keyBy('id')
                    ->all();
            });
    }

    /**
     * @return array<int, Location>
     */
    public static function getActiveByBranch(int $branchId): array
    {
        $key = self::CACHE_KEY_ACTIVE_BY_BRANCH.'-'.$branchId;

        return Location::cache()
            ->tags("branch:{$branchId}")
            ->remember($key, function () use ($branchId) {
                return Location::query()
                    ->active()
                    ->where('branch_id', $branchId)
                    ->orderBy('name')
                    ->get()
                    ->keyBy('id')
                    ->all();
            });
    }

    public static function getWithTables(int $id): ?Location
    {
        $location = self::getById($id);
        if (! $location) {
            return null;
        }

        return Location::cache()
            ->tags(["location:{$id}", "branch:{$location->branch_id}"])
            ->remember("with-tables-{$id}", function () use ($id) {
                return Location::with('tables')->find($id);
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    public static function delete(int $id, int $deletedBy): bool
    {
        $location = self::getById($id);
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
}

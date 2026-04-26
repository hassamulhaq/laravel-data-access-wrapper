<?php

namespace App\Repositories;

use App\Models\Location;
use App\Models\Table;
use Illuminate\Database\Eloquent\Collection;

class TableRepository
{
    public const string CACHE_KEY_BY_BRANCH = 'by_branch';

    public const string CACHE_KEY_BY_LOCATION = 'by_location';

    public const string CACHE_KEY_AVAILABLE_BY_LOCATION = 'available_by_location';

    public const string CACHE_KEY_BY_SLUG = 'slug';

    public const string CACHE_KEY_INDEX = 'index';

    /*
    |--------------------------------------------------------------------------
    | Index — tables (with location relation) + active locations picker.
    | Single cached payload. Pass null branch_id for super-admin (all branches).
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{tables: array<int, Table>, locations: array<int, Location>}
     */
    public static function getIndexFor(?int $branchId): array
    {
        $key = self::CACHE_KEY_INDEX.($branchId ? "-branch-{$branchId}" : '-all');
        $tags = $branchId ? ['tables', "branch:{$branchId}"] : ['tables', 'locations'];

        $payload = Table::cache()
            ->tags($tags)
            ->remember($key, function () use ($branchId) {
                $tableQuery = Table::query();
                $locationQuery = Location::query()->where('is_active', true);
                if ($branchId) {
                    $tableQuery->where('branch_id', $branchId);
                    $locationQuery->where('branch_id', $branchId);
                }
                $tables = $tableQuery->orderByDesc('created_at')->get();
                // Pull every location referenced by these tables PLUS active picker rows.
                $referencedIds = $tables->pluck('location_id')->filter()->unique();
                $locations = $locationQuery
                    ->orWhereIn('id', $referencedIds)
                    ->get();

                return [
                    'tables' => $tables->map->getAttributes()->all(),
                    'locations' => $locations->map->getAttributes()->all(),
                ];
            });
        $locations = Location::hydrate($payload['locations'] ?? []);
        $locationsById = $locations->keyBy('id');
        $tables = Table::hydrate($payload['tables'] ?? [])
            ->each(function (Table $table) use ($locationsById) {
                if ($table->location_id && $locationsById->has($table->location_id)) {
                    $table->setRelation('location', $locationsById->get($table->location_id));
                }
            });
        // Picker only contains active locations (filter; cached payload may include
        // referenced-but-inactive rows so relation attachment works).
        $picker = $locations->where('is_active', true)->values();

        return [
            'tables' => $tables->all(),
            'locations' => $picker->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reads — cache attribute arrays, rehydrate via Eloquent.
    |--------------------------------------------------------------------------
    */

    public static function getById(int $id): ?Table
    {
        $attrs = Table::cache()->remember($id, fn () => Table::query()->find($id)?->getAttributes());

        return self::makeOne($attrs);
    }

    public static function getByIdWithTrashed(int $id): ?Table
    {
        $attrs = Table::cache()->remember(
            "trashed-{$id}",
            fn () => Table::withTrashed()->find($id)?->getAttributes(),
        );

        return self::makeOne($attrs);
    }

    public static function getBySlug(string $slug, int $branchId): ?Table
    {
        $key = self::CACHE_KEY_BY_SLUG.'-'.$slug.'-'.$branchId;
        $attrs = Table::cache()->remember($key, function () use ($slug, $branchId) {
            return Table::query()
                ->where('slug', $slug)
                ->where('branch_id', $branchId)
                ->first()?->getAttributes();
        });

        return self::makeOne($attrs);
    }

    /**
     * @return array<int, Table>
     */
    public static function getByBranch(int $branchId): array
    {
        $key = self::CACHE_KEY_BY_BRANCH.'-'.$branchId;
        $rows = Table::cache()
            ->tags("branch:{$branchId}")
            ->remember($key, fn () => Table::query()
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->get()->map->getAttributes()->all());

        return self::makeMany($rows);
    }

    /**
     * @return array<int, Table>
     */
    public static function getByLocation(int $locationId): array
    {
        $key = self::CACHE_KEY_BY_LOCATION.'-'.$locationId;
        $rows = Table::cache()
            ->tags("location:{$locationId}")
            ->remember($key, fn () => Table::query()
                ->where('location_id', $locationId)
                ->orderBy('name')
                ->get()->map->getAttributes()->all());

        return self::makeMany($rows);
    }

    /**
     * @return array<int, Table>
     */
    public static function getAvailableByLocation(int $locationId): array
    {
        $key = self::CACHE_KEY_AVAILABLE_BY_LOCATION.'-'.$locationId;
        $rows = Table::cache()
            ->tags("location:{$locationId}")
            ->remember($key, fn () => Table::query()
                ->available()
                ->where('location_id', $locationId)
                ->orderBy('name')
                ->get()->map->getAttributes()->all());

        return self::makeMany($rows);
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    public static function manage(array $data, ?int $id = null): ?Table
    {
        if (! isset($data['updated_by'])) {
            return null;
        }
        $columns = Table::getFillableColumns();
        $table = $id ? Table::query()->find($id) : null;
        if ($id && ! $table) {
            return null;
        }
        if (! $table) {
            $table = new Table;
            $table->created_by = $data['updated_by'];
        }
        foreach ($data as $key => $value) {
            if (in_array($key, $columns, true)) {
                $table->{$key} = $value;
            }
        }
        $table->save();

        return $table;
    }

    public static function delete(int $id, int $deletedBy): bool
    {
        $table = Table::query()->find($id);
        if (! $table) {
            return false;
        }
        $table->updated_by = $deletedBy;
        $table->save();

        return (bool) $table->delete();
    }

    public static function restore(int $id, int $restoredBy): ?Table
    {
        $table = Table::withTrashed()->find($id);
        if (! $table || ! $table->trashed()) {
            return null;
        }
        $table->updated_by = $restoredBy;
        $table->restore();

        return $table;
    }

    /*
    |--------------------------------------------------------------------------
    | State helpers — common write operations on tables
    |--------------------------------------------------------------------------
    */

    public static function reserve(int $id, int $updatedBy): ?Table
    {
        return self::manage([
            'is_reserved' => true,
            'is_occupied' => false,
            'updated_by' => $updatedBy,
        ], $id);
    }

    public static function occupy(int $id, int $updatedBy): ?Table
    {
        return self::manage([
            'is_occupied' => true,
            'is_reserved' => false,
            'updated_by' => $updatedBy,
        ], $id);
    }

    public static function release(int $id, int $updatedBy): ?Table
    {
        return self::manage([
            'is_reserved' => false,
            'is_occupied' => false,
            'updated_by' => $updatedBy,
        ], $id);
    }

    /*
    |--------------------------------------------------------------------------
    | Hydration
    |--------------------------------------------------------------------------
    */

    private static function makeOne(?array $attrs): ?Table
    {
        return $attrs ? Table::hydrate([$attrs])->first() : null;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return array<int, Table>
     */
    private static function makeMany(?array $rows): array
    {
        if (! $rows) {
            return [];
        }
        /** @var Collection<int, Table> $models */
        $models = Table::hydrate($rows);

        return $models->keyBy->getKey()->all();
    }
}

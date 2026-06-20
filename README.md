# Laravel Data Access Wrapper

A scalable caching layer for Laravel Eloquent models and repositories. Instead
of caching whole query results (where one row change flushes entire pages), it
**caches entities individually and reconstructs lists from cached IDs**, giving
surgical invalidation: updating one row flushes only that row's cache entry.

```
❌ Query-level cache                    ✅ Entity + ID-list cache
  key: products:page:1                    product:1 -> {…}
  val: [{id:1,…},{id:2,…},…]              product:2 -> {…}
  update product 1 -> flush whole page    list:page:1 -> [1, 2, 7, 23, …]  (IDs only)
                                          update product 1 -> flush product:1 only
```

## Requirements

- PHP `^8.1`
- Laravel `^10 | ^11 | ^12 | ^13`
- A cache store that supports **tags** (`redis`, `memcached`, or `array` in tests).
  File and database stores do **not** support tags.
- Optional: `stancl/tenancy` — when present, every cache key is auto-prefixed
  with the tenant ID; otherwise keys are prefixed with `global`.

## Installation

```bash
composer require hassam/data-access-wrapper
```

The service provider is auto-discovered. Publish the version map:

```bash
php artisan vendor:publish --tag=cache-versions-config
```

This publishes `config/cache_versions.php`.

## Concepts

| Class | Responsibility |
|-------|----------------|
| `BaseModel` | Base Eloquent model. Auto-registers the cache observer, declares `cacheBy()`, exposes table helpers. |
| `BaseRepository` | Read/write API. `findBy`/`paginate` read through the cache; `manage()` writes. |
| `Cache\EntityCache` | Stores single rows under each `cacheBy()` column. |
| `Cache\ListCache` | Stores paginated/filtered results as ID arrays only. |
| `Observers\ModelCacheObserver` | Invalidates cache on created/updated/deleted/restored. |

### Key format

```
{tenant}:{tag}:{version}:{column}:{value}     entity
{tenant}:{tag}:{version}:list:{md5(params)}   ID list
```

`tag` is the model's `cacheTag()` (table name by default). `version` comes from
`config/cache_versions.php` — bump a value to instantly orphan every key for
that model (old keys expire by TTL; no scan, no flush).

## Usage

### 1. Extend `BaseModel`

```php
use Hassam\DataAccessWrapper\BaseModel;

class Product extends BaseModel
{
    protected $fillable = ['name', 'slug', 'category_id', 'price'];

    // UNIQUE lookup columns only. Defaults to ['id'] if omitted.
    public function cacheBy(): array
    {
        return ['id', 'slug'];
    }

    // Changing these alters list membership/order -> flush list cache.
    // Omit to always flush lists on update (safe default).
    protected array $listInvalidatingColumns = ['name', 'category_id'];
}
```

> Every `cacheBy()` column **must be uniquely indexed**. A non-unique column
> (e.g. `branch_id`) would make every row overwrite the previous one.

Add the tag to `config/cache_versions.php`:

```php
return [
    'products' => 'v1',
];
```

### 2. Extend `BaseRepository`

```php
use Hassam\DataAccessWrapper\BaseRepository;
use Illuminate\Database\Eloquent\Builder;

class ProductRepository extends BaseRepository
{
    protected function model(): string { return Product::class; }
    protected function cacheTag(): string { return 'products'; }
    protected function eagerLoads(): array { return ['category']; }

    protected function buildQuery(array $filters = []): Builder
    {
        return Product::query()
            ->when($filters['search'] ?? null, fn (Builder $q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name');
    }
}
```

### 3. Read and write

```php
$repo = new ProductRepository;

$repo->findById(1);                 // entity cache -> DB on miss
$repo->findBy('slug', 'a-widget');  // any cacheBy() column

$page = $repo->paginate(['search' => 'wid'], page: 1, perPage: 50);

$repo->manage('create', ['name' => 'Widget', 'slug' => 'widget']);
$repo->manage('update', ['price' => 9.99], id: 1);
$repo->manage('delete', id: 1);
```

Invalidation is automatic via the observer:

| Operation | Entity cache | List cache |
|-----------|--------------|------------|
| create | pre-warmed | flushed (totals/order change) |
| update | flushed + re-warmed | flushed only if a `listInvalidatingColumns` value changed |
| delete | flushed | flushed |

Manual control when bypassing Eloquent (raw query-builder writes):

```php
$repo->invalidate($id);       // one entity
$repo->invalidateLists();     // all lists for the model
$repo->invalidateAll();       // entities + lists
```

## What it does not handle

- **Transactions** — cache writes fire on model events, not on commit. Wrap
  `manage()` in a transaction and re-invalidate in a catch block if you need
  transactional consistency.
- **Stock / counters** — never cache values that require row-level locking;
  read those from the DB with `lockForUpdate()`.

## Testing

```bash
composer test
```

## License

MIT.

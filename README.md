# Laravel Data Access Wrapper

A Laravel package that provides a thin, versioned cache wrapper on top of Laravel's `Cache` facade, plus an Eloquent trait that auto-flushes a model's cache on save/update/delete. Optionally ships a MongoDB-backed cache logger for storing computed dashboard payloads.

## Requirements

- PHP `^8.1`
- Laravel `^10.0 | ^11.0 | ^12.0 | ^13.0`
- (Optional) `ext-mongodb` and `mongodb/mongodb` if you use `CacheLogger` / `MongoAbstractDataCollection`

## Installation

```bash
composer require hassam/data-access-wrapper
```

The service provider, facade, and config files are auto-discovered.

Publish configs:

```bash
php artisan vendor:publish --provider="Hassam\DataAccessWrapper\DataAccessWrapperServiceProvider"
```

This publishes:

- `config/data-access-wrapper.php` — package settings
- `config/cache-versions.php` — per-table cache version map (see "Cache versioning")

> The file is named `cache-versions.php` (not `cache.php`) to avoid colliding with Laravel's built-in `config/cache.php` (drivers/stores). The `Cacheable` trait reads `config('cache-versions.version.{table}')`.

---

## Concepts

### `BaseCache`

A wrapper around `Illuminate\Support\Facades\Cache` that namespaces every key with three parts:

```
<global-version> + <model-version> + <key-prefix> + <user-key>
```

- **global-version** — hard-coded `prod:1` constant inside `BaseCache`. Bump it to invalidate **everything** at once (e.g. on a deploy that breaks all serialized cache shapes).
- **model-version** — per-table version from `config/cache-versions.php` (e.g. `companies => v3`). Bump a single table's version to invalidate only that table's cache.
- **key-prefix** — usually `-{table_name}-`, set automatically by the `Cacheable` trait.
- **user-key** — whatever you pass to `remember/get/put/forget`.

This three-tier scheme means you never have to call `php artisan cache:clear` to roll out a schema change — bump the table's version in `config/cache-versions.php` and old keys become unreachable.

### `BaseModel`

An optional thin Eloquent base class. Use it when you want models to share a few small, opinionated defaults:

- `getTableName()`, `getTableColumns()`, `getFillableColumns()` — schema/fillable introspection.
- `serializeDate()` — ISO `Y-m-d H:i:s` for every JSON-serialized date.
- `save()` strips appended (accessor-driven) attributes before persistence so virtual fields don't get written as columns.

You can mix or skip it — the cache wrapper does not require it.

### `Cacheable` trait

Drop on any Eloquent model. It hooks `saved`, `updated`, and `deleted` events to flush the cache keys the model declares via `cacheBy()` and `cacheKey()`. Use this if you want automatic invalidation; otherwise keep cache flushing inside your repository's write methods (recommended for explicit control — see "Repository pattern" below).

### `GeneralCache`

Static facade-style accessor for app-global cache values (SMS codes, OTPs, browser fingerprints, third-party tokens, etc.). Extends `BaseCache` with a fixed prefix `-general_cache-`.

### `CacheLogger` (optional, requires MongoDB)

Persists computed payloads (e.g. admin dashboards, aggregated stats) into a Mongo `cache` collection so jobs can pre-compute them and consumers can read them without recomputation.

---

## Usage

### 1. Make an Eloquent model cacheable

```php
<?php

namespace App\Models;

use Hassam\DataAccessWrapper\Traits\Cacheable;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use Cacheable;

    protected $table = 'articles';

    /**
     * Cache keys to invalidate when this row changes.
     *
     * Each entry is a list of model attributes whose values get
     * concatenated into a key (so updating row id=42 flushes
     * `<prefix>42`).
     */
    public function cacheBy(): array
    {
        return [
            ['id'],
            ['slug'],
            'by_author' => ['author_id'],
        ];
    }

    /**
     * Optional extra "static" key to flush on every change.
     */
    public function cacheKey(): string
    {
        return 'all';
    }
}
```

Then register the table's version in `config/cache-versions.php`:

```php
return [
    'version' => [
        'articles' => 'v1',
        // ...
    ],
];
```

### 2. Read through cache in a repository

```php
<?php

namespace App\Repositories;

use App\Models\Article;
use Illuminate\Support\Collection;

class ArticleRepository
{
    public const CACHE_ALL = 'all';

    public static function getAll(): Collection
    {
        return Article::cache()->remember(self::CACHE_ALL, function () {
            return Article::query()->get();
        });
    }

    public static function find(int $id): ?Article
    {
        return Article::cache()->remember($id, function () use ($id) {
            return Article::query()->find($id);
        });
    }
}
```

`Article::cache()` returns a `BaseCache` instance scoped to the `articles` table and its current version from config. `remember($key, $callback, $bypass = false, $ttl = 15768000)` mirrors Laravel's `Cache::remember()` with the namespaced key and a default 6-month TTL.

You can also use the lower-level methods directly:

```php
Article::cache()->put($id, $article);
Article::cache()->get($id);
Article::cache()->forget($id);
Article::cache()->pull($id);
```

### 3. Invalidating cache

You usually don't have to. The `Cacheable` trait flushes `cacheBy()` keys automatically on save/update/delete. Manual flush:

```php
$article->deleteCache();         // flushes everything in cacheBy() + cacheKey()
Article::cache()->forget('all'); // targeted flush
```

For a bulk invalidation across the whole table, bump the version in config:

```php
// config/cache-versions.php
'articles' => 'v2', // was v1 — old keys are now unreachable
```

### 4. App-global values via `GeneralCache`

```php
use Hassam\DataAccessWrapper\GeneralCache;

GeneralCache::setData('feature_flag.x', true, 600);
GeneralCache::getData('feature_flag.x');

GeneralCache::setSMSCode($userId, 'login_form');
$entry = GeneralCache::getSMSCode($userId);

GeneralCache::setUserBrowserDetails($userId, ['ua' => '...']);
```

> `GeneralCache::setSMSCode()` references an `Acme\Core\Enums\SMSForm` class for the `allowed_route` lookup. Replace the import at the top of `GeneralCache.php` with your own enum/class, or remove that branch if you don't need form-route gating.

### 5. Long-form cache via `CacheLogger` (optional)

Used to pre-compute heavy aggregates (e.g. dashboards) in a scheduled job and read them cheaply on request.

```php
use Hassam\DataAccessWrapper\CacheLogger;

// Producer (scheduled job/command)
$payload = $this->computeAdminDashboard();
CacheLogger::manage(CacheLogger::CACHE_DATA_KEY_ADMIN_DASHBOARD, $payload);

// Consumer (controller / endpoint)
$payload = CacheLogger::getDataValueByDataKey(
    CacheLogger::CACHE_DATA_KEY_ADMIN_DASHBOARD,
    default: []
);
$lastUpdatedAt = CacheLogger::getLastUpdatedAt(
    CacheLogger::CACHE_DATA_KEY_ADMIN_DASHBOARD
);
```

Required config (in your app's `config/database.php`):

```php
'connections' => [
    'mongodb' => [
        'driver'   => 'mongodb',
        'dsn'      => env('MONGO_DSN'),
        'database' => env('MONGO_DATABASE'),
    ],
],
```

And `config('common.LIMIT_MONGO_LOGGING_FETCH')` for fetch limits.

---

## Repository pattern

This package is designed to live alongside a thin **repository layer**. Repositories own all reads (cache-aside via `Model::cache()->remember()`) and all writes (one entry-point per aggregate, with explicit cache flushing). Models stay dumb — they describe schema and relationships, not workflow.

Recommended layout per module:

```
app/
  Models/
    Order.php                 // extends BaseModel, declares schema + casts
    OrderItem.php
  Repositories/
    OrderRepository.php       // public API for the rest of the app
    OrderItemRepository.php
  Caches/
    OrderCache.php            // optional: per-aggregate BaseCache subclass
```

### 1. Model — keep it lean

```php
<?php

namespace App\Models;

use Hassam\DataAccessWrapper\BaseModel;
use Hassam\DataAccessWrapper\Traits\Cacheable;

class Order extends BaseModel
{
    use Cacheable;

    protected $table = 'orders';

    protected $fillable = [
        'customer_id', 'status', 'total_amount',
        'currency', 'placed_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'placed_at'    => 'datetime',
    ];

    public function cacheBy(): array
    {
        return [
            ['id'],
            'by_customer' => ['customer_id'],
        ];
    }
}
```

### 2. Repository — single `manage()` write entry-point

The `manage()` method is the only public write path. It handles both create and update through the same call, whitelists keys against the model's fillable list, and flushes the cache after a successful save.

```php
<?php

namespace App\Repositories;

use App\Models\Order;
use Hassam\DataAccessWrapper\BaseCache;

class OrderRepository
{
    public const CACHE_KEY_BY_CUSTOMER = 'by_customer';

    public static function find(int $orderId): ?Order
    {
        return Order::cache()->remember($orderId, function () use ($orderId) {
            return Order::query()->find($orderId);
        });
    }

    public static function getByCustomer(int $customerId): array
    {
        $key = self::CACHE_KEY_BY_CUSTOMER . '-' . $customerId;

        return Order::cache()->remember($key, function () use ($customerId) {
            return Order::query()
                ->where('customer_id', $customerId)
                ->get()
                ->keyBy('id')
                ->all();
        });
    }

    /**
     * Single write entry-point. Pass $orderId to update, omit to create.
     * Returns null if the caller forgot to set updated_by, or if an
     * update was requested for a row that no longer exists.
     */
    public static function manage(array $data, ?int $orderId = null): ?Order
    {
        if (! isset($data['updated_by'])) {
            return null;
        }

        $columns = Order::getFillableColumns();
        $order   = $orderId ? self::find($orderId) : null;

        if ($orderId && ! $order) {
            return null;
        }

        if (! $order) {
            $order = new Order();
            $order->created_by = $data['updated_by'];
        }

        foreach ($data as $key => $value) {
            if (in_array($key, $columns, true)) {
                $order->{$key} = $value;
            }
        }

        $order->save();

        self::flushCache($order);

        return $order;
    }

    public static function delete(int $orderId, int $deletedBy): bool
    {
        $order = self::find($orderId);
        if (! $order) {
            return false;
        }

        $order->updated_by = $deletedBy;
        $order->save();
        $deleted = $order->delete();

        self::flushCache($order);

        return (bool) $deleted;
    }

    protected static function flushCache(Order $order): void
    {
        // Per-row keys flush automatically via Cacheable's model events.
        // Flush any list-style keys this repository owns explicitly:
        Order::cache()->forget(self::CACHE_KEY_BY_CUSTOMER . '-' . $order->customer_id);
    }
}
```

### 3. Per-aggregate cache helper (optional)

When a repository accumulates many keys, group them in a small `BaseCache` subclass:

```php
<?php

namespace App\Caches;

use Hassam\DataAccessWrapper\BaseCache;

class OrderCache extends BaseCache
{
    public static function make(): self
    {
        return new self('-orders-', config('cache-versions.version.orders', 'v1'));
    }

    public function dashboardSummary(int $customerId)
    {
        return $this->get("dashboard-summary-{$customerId}");
    }

    public function setDashboardSummary(int $customerId, array $payload, int $ttl = 600): bool
    {
        return $this->put("dashboard-summary-{$customerId}", $payload, $ttl);
    }

    public function forgetDashboardSummary(int $customerId): bool
    {
        return $this->forget("dashboard-summary-{$customerId}");
    }
}
```

Used from the repository:

```php
public static function dashboardSummary(int $customerId): array
{
    $cache = OrderCache::make();
    $cached = $cache->dashboardSummary($customerId);
    if ($cached) {
        return $cached;
    }

    $summary = self::computeDashboardSummary($customerId);
    $cache->setDashboardSummary($customerId, $summary);

    return $summary;
}
```

### Why this shape

- **One write entry-point (`manage`)** — `if ($id) update else create` lives in one place. Callers pass `$data + $id?` and never construct models.
- **Fillable whitelist** — `getFillableColumns()` keeps unauthorized columns out of writes, even if `$data` is built from a request payload.
- **`updated_by` is mandatory** — every write has an audit trail. The early `null` return is a soft contract: forget the actor, no write happens.
- **Reads are cache-aside** — `Model::cache()->remember()` is the only way the repository talks to the DB for reads. Bumping the table's version in `config/cache-versions.php` invalidates everything for that aggregate.
- **Cache flush is explicit at write time** — no event-magic for list-style keys; you write the flush next to the save.

---

## Recipe: schedule pre-computed dashboards

```php
// app/Console/Commands/CacheAdminDashboard.php
use Hassam\DataAccessWrapper\CacheLogger;

public function handle(): int
{
    $stats = [
        'total_users'    => User::count(),
        'total_revenue'  => Transaction::sum('amount'),
        'computed_at'    => now()->toIso8601String(),
    ];

    CacheLogger::manage(CacheLogger::CACHE_DATA_KEY_ADMIN_DASHBOARD, $stats);

    return self::SUCCESS;
}
```

Then schedule in `app/Console/Kernel.php`:

```php
$schedule->command('cache:admin-dashboard')->everyFifteenMinutes();
```

---

## API reference (cheat sheet)

`BaseCache` — instance methods:

| Method | Purpose |
|---|---|
| `remember($key, Closure $cb, $bypass = false, $ttl = 15768000)` | Cache-aside read; `$bypass = true` forces recompute. |
| `put($key, $data, $ttl = 15768000): bool` | Write with namespaced key. |
| `get($key)` | Read with namespaced key. |
| `pull($key)` | Read + forget atomically. |
| `forget($key): bool` | Invalidate one key. |
| `*WithoutPrefix($key, ...)` | Same as above but skips namespacing — use for cross-component values. |
| `getKey($key): string` | Returns the fully-namespaced key string. |

`Cacheable` — for your model:

| Member | Purpose |
|---|---|
| `cacheBy(): array` *(abstract)* | Tuples of attributes whose changes flush keys. |
| `cacheKey(): string` *(optional)* | An extra static key flushed on every change. |
| `static cache(): BaseCache` | Returns a table-scoped `BaseCache`. |
| `deleteCache(): void` | Flush manually. |

---

## Conventions

- One `version` entry per table. Default `v1`. Bump when the cached shape changes.
- TTLs default to 6 months (`15768000` seconds). Pass shorter TTLs for volatile data.
- Use `remember()` in repositories, not in controllers/services. Keeps cache logic in one place.
- Never call `Cache::*` directly from a model with `Cacheable` — the trait owns the keys.

## License

MIT

# BaseModel, Repositories, and Cache Tags

A practical guide for building a model + repository layer on top of this package, plus the full `Cache::tags` story (how to use it with repositories and relations).

This complements the main [README.md](README.md) — read that first for the cache fundamentals (`BaseCache`, `Cacheable`, versioning).

---

## Table of contents

1. [BaseModel — what and when](#1-basemodel)
2. [Repository pattern](#2-repository-pattern)
3. [The `manage()` write entry-point](#3-the-manage-write-entry-point)
4. [Read methods (`getById`, `getByX`, lists)](#4-read-methods)
5. [Cleaning the cache](#5-cleaning-the-cache)
6. [Cache tags — light, medium, complex](#6-cache-tags)
7. [Putting it all together](#7-putting-it-all-together)

---

## 1. BaseModel

`Hassam\DataAccessWrapper\BaseModel` is an **abstract** Eloquent base class with five small primitives. Use it on every domain model so the repository layer has a consistent interface to work against.

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

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function cacheBy(): array
    {
        return [
            ['id'],
            'by_customer' => ['customer_id'],
        ];
    }
}
```

### What BaseModel gives you

| Method | Purpose |
|---|---|
| `static getTableName(): string` | Returns the `$table` name. Used by `Cacheable::cache()`. |
| `static getTableColumns(?string $table = null): array` | Real columns from `Schema::getColumnListing()`. |
| `static getFillableColumns(): array` | The model's `$fillable` array — drives the `manage()` whitelist. |
| `serializeDate(DateTimeInterface $date): string` | ISO `Y-m-d H:i:s` for every JSON-serialized date. |
| `save(array $options = [])` | Strips `$appends` accessor values before persisting so virtual fields don't get written as columns. |

### Do you need it?

Optional. The cache wrapper works with any `Illuminate\Database\Eloquent\Model`. Use `BaseModel` when:

- You want a single `manage()` shape across repositories (relies on `getFillableColumns()`).
- You want consistent ISO date output across all JSON responses.
- You have models with `$appends` and don't want them sneaking into writes.

If those don't apply, extend `Model` directly.

---

## 2. Repository pattern

The repository is the **only** place the rest of your app talks to a model. Controllers, jobs, listeners, console commands — all go through repositories. This gives you one place to put cache reads, write coordination, audit logging, and invalidation.

Layout per module:

```
app/
  Models/
    Order.php
    OrderItem.php
    Customer.php
  Repositories/
    OrderRepository.php
    OrderItemRepository.php
  Caches/
    OrderCache.php          // optional: BaseCache subclass for grouped keys
```

### Rules of thumb

- **Reads** go through `Model::cache()->remember(...)`.
- **Writes** go through one method per aggregate: `manage()`.
- **Invalidation** happens in the same method as the write — never let mutation leak without a paired flush.
- **`updated_by` is required** on every write. If it's missing, refuse the write.
- Repositories are **stateless static classes** — no constructors, no DI. Easier to call from anywhere.

---

## 3. The `manage()` write entry-point

One method handles both create and update. The caller passes `$data + $id?`. Whatever fields are in `$data` and pass the fillable whitelist get written; the rest are ignored.

```php
<?php

namespace App\Repositories;

use App\Models\Order;
use App\Caches\OrderCache;

class OrderRepository
{
    public const CACHE_KEY_BY_CUSTOMER = 'by_customer';
    public const CACHE_KEY_LIST_RECENT = 'recent';

    /**
     * Create or update. Returns the saved model, or null if:
     *   - $data has no updated_by (refuse silent writes), or
     *   - $orderId is provided but no row exists.
     */
    public static function manage(array $data, ?int $orderId = null): ?Order
    {
        if (! isset($data['updated_by'])) {
            return null;
        }

        $columns = Order::getFillableColumns();
        $order   = $orderId ? self::getById($orderId) : null;

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
}
```

### Why this shape

- **Fillable whitelist** — even if `$data` is built from `$request->all()`, only declared columns reach the model.
- **`updated_by` gate** — every write has an actor. The early `null` return is a soft contract: the caller must provide it.
- **Idempotent miss** — updating a deleted row returns `null` instead of accidentally inserting a new one.
- **Cache flush is part of the write path** — never separated from the save.

---

## 4. Read methods

Cache-aside via `Model::cache()->remember()`.

```php
public static function getById(int $orderId): ?Order
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
            ->orderByDesc('placed_at')
            ->get()
            ->keyBy('id')
            ->all();
    });
}

public static function getRecent(int $limit = 50): array
{
    $key = self::CACHE_KEY_LIST_RECENT . '-' . $limit;

    return Order::cache()->remember($key, function () use ($limit) {
        return Order::query()
            ->orderByDesc('placed_at')
            ->limit($limit)
            ->get()
            ->all();
    }, byPassCache: false, ttl: 300);
}

public static function getWithItems(int $orderId): ?Order
{
    return Order::cache()->remember("with-items-{$orderId}", function () use ($orderId) {
        return Order::with('items')->find($orderId);
    });
}
```

### Reading-related rules

- **Key shape:** prefix the key with the *list name* and append the parameters that change the result. Different params → different keys.
- **TTL:** the default is 6 months (relies on version-bump invalidation). For volatile lists, pass a short TTL.
- **Don't cache `null` separately:** `remember()` already skips writes for `null`, so a missing row will recompute on the next call.

---

## 5. Cleaning the cache

Three ways, in order of preference:

### a) Targeted forget (single key)

```php
Order::cache()->forget($orderId);
Order::cache()->forget(OrderRepository::CACHE_KEY_BY_CUSTOMER . '-' . $customerId);
```

Use this in `flushCache()` inside `manage()`:

```php
protected static function flushCache(Order $order): void
{
    Order::cache()->forget($order->id);
    Order::cache()->forget(self::CACHE_KEY_BY_CUSTOMER . '-' . $order->customer_id);
    Order::cache()->forget("with-items-{$order->id}");
}
```

The `Cacheable` trait already auto-flushes the keys declared in `cacheBy()` via Eloquent events — `flushCache()` only needs to handle list-style keys that the trait can't know about (recent lists, joined views, etc.).

### b) Tagged flush (group of keys)

```php
Order::cache()->tags("customer:{$customerId}")->flush();
```

Wipes every entry tagged with that customer in one call. Covered in the next section.

### c) Version bump (whole table)

```php
// config/cache-versions.php
'orders' => 'v2', // was 'v1'
```

Old keys become unreachable. Best for schema migrations.

### Auto-flush via the `Cacheable` trait

When a model uses `Cacheable`, the trait registers Eloquent listeners and flushes cache automatically on:

| Event | When it fires |
|---|---|
| `saved` | After `created` and after `updated` (covers inserts and updates). |
| `deleted` | After `delete()` (soft or hard). |
| `restored` | After `restore()` on `SoftDeletes` models. |
| `forceDeleted` | After `forceDelete()` on `SoftDeletes` models. |

> The trait listens to `saved` instead of `updated` so an update doesn't double-flush.

What gets flushed:

1. **Per-row keys from `cacheBy()`** — built from current attribute values.
2. **Per-row keys for changed attributes** — also flushed using the *original* attribute values, so stale entries don't leak when (e.g.) `customer_id` changes from 5 → 10.
3. **The static `cacheKey()`** if non-empty.
4. **Every tag returned by `cacheTagsForFlush()`** — auto-flushed via `Cache::tags()`.

#### `cacheTagsForFlush()` — opt-in tag invalidation

Override this method on your model to enable tag-based auto-flush. It receives `$this`, so you can build per-row tags and detect attribute changes:

```php
class Order extends BaseModel
{
    use Cacheable;

    public function cacheBy(): array
    {
        return [
            ['id'],
            'by_customer' => ['customer_id'],
        ];
    }

    public function cacheTagsForFlush(): array
    {
        $tags = [
            "order:{$this->id}",
            "customer:{$this->customer_id}",
        ];

        // Customer reassigned? wipe the OLD customer's caches too.
        if ($this->wasChanged('customer_id')) {
            $tags[] = "customer:{$this->getOriginal('customer_id')}";
        }

        return $tags;
    }
}
```

Now any `$order->save()`, `$order->delete()`, or `$order->restore()` will:

- Forget per-row keys (current + original values).
- Flush every cache tagged `order:{id}` and `customer:{id}` — including caches written by *other* repositories that joined the same tag bus.

#### When to override and when to leave default

- Leave default (`return []`) on models that don't participate in tagged caches. The trait still auto-flushes per-row keys.
- Override when the model's writes should invalidate **other repositories'** caches that joined a shared tag (cross-aggregate joins, customer dashboards, etc.).
- Use the `wasChanged()` / `getOriginal()` helpers inside it whenever an attribute change should invalidate the *old* value's caches as well.

#### Limits of auto-flush

The trait only fires on **Eloquent model events**. It does **not** fire on:

- Mass updates / mass deletes via the query builder (`Order::where(...)->update(...)` / `->delete()`). These bypass model events entirely. Either iterate models, or call `OrderRepository::flushCache(...)` manually after the bulk op.
- Direct DB writes via `DB::table('orders')->...`.
- Writes from external systems (other services, replication, raw SQL migrations).

For these paths, fall back to:
```php
Order::cache()->tags("customer:{$customerId}")->flush();
```
in the same code path that did the write.

---

## 6. Cache tags

`Cache::tags(...)` lets you group keys by labels and flush a whole group with a single call. This package wraps it on `BaseCache`:

```php
$cache = Order::cache()->tags(['orders', "customer:{$id}"]);
$cache->put('list', $payload, 600);
$cache->flush(); // wipes everything tagged 'orders' OR "customer:$id"
```

> **Store requirement:** tags work on **Redis**, **Memcached**, **array**, **DynamoDB**. The `file` and `database` drivers throw on tag operations. If you can't run Redis/Memcached, stick with version bumps + targeted forgets.

> **Immutability:** `tags()` returns a **new** `BaseCache` instance. The original is untouched, so you can hand around the base instance without worrying about polluted tag state.

> **`flush()` safety:** Without any tag, `flush()` throws `BadMethodCallException`. This stops you from accidentally wiping the entire app cache.

### Light example — flush a feature-area in one call

You have a few cached endpoints for a "popular articles" widget. When the editor publishes anything new, you want to wipe all of them.

```php
class ArticleRepository
{
    public static function getPopular(): array
    {
        return Article::cache()
            ->tags('articles:popular')
            ->remember('all', fn() => Article::popular()->get()->all(), ttl: 600);
    }

    public static function getTrending(): array
    {
        return Article::cache()
            ->tags('articles:popular')
            ->remember('trending', fn() => Article::trending()->get()->all(), ttl: 600);
    }

    public static function flushPopular(): void
    {
        Article::cache()->tags('articles:popular')->flush();
    }
}
```

`flushPopular()` clears both keys in O(1). No need to remember every key name.

### Medium example — per-customer scoping

A customer's order list, total, and pending count are three separate caches. When *any* of their orders change, you want all three gone — without touching other customers.

```php
class OrderRepository
{
    public static function getByCustomer(int $customerId): array
    {
        return self::customerCache($customerId)
            ->remember('list', function () use ($customerId) {
                return Order::query()->where('customer_id', $customerId)->get()->all();
            });
    }

    public static function getTotalForCustomer(int $customerId): string
    {
        return self::customerCache($customerId)
            ->remember('total', function () use ($customerId) {
                return (string) Order::query()->where('customer_id', $customerId)->sum('total_amount');
            });
    }

    public static function getPendingCountForCustomer(int $customerId): int
    {
        return self::customerCache($customerId)
            ->remember('pending-count', function () use ($customerId) {
                return Order::query()
                    ->where('customer_id', $customerId)
                    ->where('status', 'pending')
                    ->count();
            });
    }

    public static function manage(array $data, ?int $orderId = null): ?Order
    {
        // ... save logic from section 3 ...
        $order = /* saved order */;

        // Wipe every cache scoped to this customer in one call:
        self::customerCache($order->customer_id)->flush();

        return $order;
    }

    protected static function customerCache(int $customerId): \Hassam\DataAccessWrapper\BaseCache
    {
        return Order::cache()->tags("customer:{$customerId}");
    }
}
```

The tag `"customer:{id}"` becomes the bus that ties together every cache that depends on that customer. Repositories elsewhere can join the same bus:

```php
// In InvoiceRepository — invoices for the same customer:
Invoice::cache()->tags("customer:{$customerId}")->flush();
// flushes both order AND invoice caches for that customer.
```

### Complex example — relations and cross-aggregate flushes

`Order` `hasMany` `OrderItem`. When an item changes, three things must die:

1. The order's "with-items" cache.
2. The customer's order-list cache (totals may have shifted).
3. Any report cache that aggregates this order.

Manually tracking all three keys from `OrderItemRepository::manage()` is brittle. Tags make it declarative.

**Tagging strategy:**

```
order:{order_id}        // every cache tied to that one order
customer:{customer_id}  // every cache tied to that customer (across aggregates)
orders                  // global "any order changed" tag — use sparingly
```

Apply the tags wherever the cache is *read*:

```php
class OrderRepository
{
    public static function getWithItems(int $orderId): ?Order
    {
        $order = Order::query()->find($orderId);
        if (! $order) {
            return null;
        }

        return Order::cache()
            ->tags(["order:{$orderId}", "customer:{$order->customer_id}"])
            ->remember("with-items-{$orderId}", function () use ($orderId) {
                return Order::with('items')->find($orderId);
            });
    }
}

class CustomerRepository
{
    public static function getDashboard(int $customerId): array
    {
        return Customer::cache()
            ->tags("customer:{$customerId}")
            ->remember("dashboard-{$customerId}", function () use ($customerId) {
                return [
                    'orders_total' => Order::query()->where('customer_id', $customerId)->sum('total_amount'),
                    'last_order'   => Order::query()->where('customer_id', $customerId)->latest('placed_at')->first(),
                    // ...
                ];
            });
    }
}
```

Now invalidation is local — each repository only knows about its own tags:

```php
class OrderItemRepository
{
    public static function manage(array $data, int $orderId, ?int $itemId = null): ?OrderItem
    {
        if (! isset($data['updated_by'])) {
            return null;
        }

        $columns = OrderItem::getFillableColumns();
        $item    = $itemId ? self::getById($itemId) : null;

        if ($itemId && ! $item) {
            return null;
        }

        if (! $item) {
            $item = new OrderItem();
            $item->order_id   = $orderId;
            $item->created_by = $data['updated_by'];
        }

        foreach ($data as $key => $value) {
            if (in_array($key, $columns, true)) {
                $item->{$key} = $value;
            }
        }

        $item->save();

        // One flush wipes BOTH:
        //  - OrderRepository::getWithItems(id) cache
        //  - any other consumer that tagged with "order:{id}"
        OrderItem::cache()->tags("order:{$orderId}")->flush();

        // Cascade up: the customer's dashboard depends on order totals
        $order = Order::query()->find($orderId);
        if ($order) {
            Order::cache()->tags("customer:{$order->customer_id}")->flush();
        }

        return $item;
    }
}
```

**Mental model:**

> When you *write* a row, flush every tag the row is "part of".
> When you *read* a derived value, tag it with every entity it derives from.
> Reads opt into the bus; writes blast on the bus.

### Tag naming conventions

| Pattern | Use for |
|---|---|
| `aggregate` (e.g. `orders`) | Wipe-everything for an entity. Sledgehammer — use sparingly. |
| `aggregate:{id}` | Caches scoped to one row. The most common pattern. |
| `relation:{id}` | Cross-aggregate joins (`customer:{id}`, `tenant:{id}`). |
| `feature:area` | Feature widgets (`articles:popular`, `dashboard:admin`). |

Avoid spaces and uppercase in tag names — it's the Redis key namespace under the hood.

---

## 7. Putting it all together

A complete `OrderRepository` showing model + cache + tags + relations:

```php
<?php

namespace App\Repositories;

use App\Models\Order;
use Hassam\DataAccessWrapper\BaseCache;

class OrderRepository
{
    public const CACHE_KEY_BY_CUSTOMER  = 'by_customer';
    public const CACHE_KEY_RECENT       = 'recent';
    public const CACHE_KEY_WITH_ITEMS   = 'with-items';

    // ---------- READS ----------

    public static function getById(int $orderId): ?Order
    {
        return Order::cache()->remember($orderId, function () use ($orderId) {
            return Order::query()->find($orderId);
        });
    }

    public static function getByCustomer(int $customerId): array
    {
        return self::customerCache($customerId)
            ->remember(self::CACHE_KEY_BY_CUSTOMER . '-' . $customerId, function () use ($customerId) {
                return Order::query()
                    ->where('customer_id', $customerId)
                    ->orderByDesc('placed_at')
                    ->get()
                    ->all();
            });
    }

    public static function getWithItems(int $orderId): ?Order
    {
        $order = self::getById($orderId);
        if (! $order) {
            return null;
        }

        return Order::cache()
            ->tags(["order:{$orderId}", "customer:{$order->customer_id}"])
            ->remember(self::CACHE_KEY_WITH_ITEMS . '-' . $orderId, function () use ($orderId) {
                return Order::with('items')->find($orderId);
            });
    }

    public static function getRecent(int $limit = 50): array
    {
        return Order::cache()
            ->tags('orders')
            ->remember(self::CACHE_KEY_RECENT . '-' . $limit, function () use ($limit) {
                return Order::query()
                    ->orderByDesc('placed_at')
                    ->limit($limit)
                    ->get()
                    ->all();
            }, byPassCache: false, ttl: 300);
    }

    // ---------- WRITES ----------

    public static function manage(array $data, ?int $orderId = null): ?Order
    {
        if (! isset($data['updated_by'])) {
            return null;
        }

        $columns = Order::getFillableColumns();
        $order   = $orderId ? self::getById($orderId) : null;

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
        $order = self::getById($orderId);
        if (! $order) {
            return false;
        }

        $order->updated_by = $deletedBy;
        $order->save();
        $deleted = (bool) $order->delete();

        self::flushCache($order);

        return $deleted;
    }

    // ---------- CACHE ----------

    protected static function customerCache(int $customerId): BaseCache
    {
        return Order::cache()->tags("customer:{$customerId}");
    }

    protected static function flushCache(Order $order): void
    {
        // Per-row keys also auto-flush via the Cacheable trait;
        // here we wipe list-style keys + every tagged consumer.
        Order::cache()->forget($order->id);
        Order::cache()->forget(self::CACHE_KEY_BY_CUSTOMER . '-' . $order->customer_id);

        Order::cache()->tags("order:{$order->id}")->flush();
        Order::cache()->tags("customer:{$order->customer_id}")->flush();
        Order::cache()->tags('orders')->flush(); // recent-list invalidation
    }
}
```

### Calling it from a controller

```php
public function update(Request $request, int $orderId): JsonResponse
{
    $data = $request->validated();
    $data['updated_by'] = $request->user()->id;

    $order = OrderRepository::manage($data, $orderId);

    return $order
        ? response()->json($order)
        : response()->json(['error' => 'Order not found or actor missing.'], 422);
}
```

That's the whole loop:

1. `BaseModel` provides `getFillableColumns()` for the whitelist.
2. The repository's `manage()` is the only write path.
3. Reads are cache-aside, tagged where they cross aggregates.
4. Writes flush by tag — invalidation is declarative, not key-by-key.

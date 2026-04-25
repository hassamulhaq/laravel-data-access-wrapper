# Tests

Pest 3 + Orchestra Testbench. In-memory SQLite + `array` cache driver (tags supported).

## Run

```bash
composer install
composer test
```

Or directly:

```bash
vendor/bin/pest
```

## Run a single suite

```bash
vendor/bin/pest --testsuite=Unit
vendor/bin/pest --testsuite=Feature
```

## Run a single file

```bash
vendor/bin/pest tests/Unit/BaseCacheTest.php
vendor/bin/pest tests/Feature/CacheableTraitTest.php
```

## Run a single test by name

```bash
vendor/bin/pest --filter='flushes original-value key when foreign key changes'
```

## Useful flags

| Flag | Use |
|---|---|
| `--parallel` | Run suites in parallel processes. |
| `--coverage` | Coverage report (needs Xdebug or PCOV). |
| `--coverage-html=coverage/` | HTML report at `coverage/index.html`. |
| `--stop-on-failure` | Bail on first red. |
| `--bail` | Same as above. |
| `-v` | Verbose output (full stack on failure). |

## Layout

```
tests/
  Pest.php                       # binds Pest tests to TestCase
  TestCase.php                   # Testbench bootstrap, SQLite, schema
  Fixtures/
    Order.php                    # Cacheable + SoftDeletes test model
  Unit/
    BaseCacheTest.php            # cache primitives + tags
    BaseModelTest.php            # table/fillable/append-strip/date format
  Feature/
    CacheableTraitTest.php       # auto-flush on save/delete/restore + tags
phpunit.xml                      # test runner config
```

## What `TestCase.php` does

- Boots `DataAccessWrapperServiceProvider`.
- Sets DB to SQLite `:memory:` — fresh schema each test.
- Sets cache driver to `array` (tag-aware).
- Seeds `cache.version` for `orders` and `order_items` tables.
- Creates `orders` + `order_items` tables in `setUp()`.

## What's covered

| Suite | Count | Coverage |
|---|---|---|
| `BaseCacheTest` | 12 | Namespaced keys, remember, falsy-value regression, forget, version bump, tag immutability + isolation + flush + safety, multi-tag, `WithoutPrefix`, pull |
| `BaseModelTest` | 4 | Table name, fillable columns, schema columns, append-strip on save, date serialization |
| `CacheableTraitTest` | 7 | Per-row flush on save/delete, original-value flush on FK change, tag flush via `cacheTagsForFlush`, old+new tag flush on FK change, restore flush, no double-fire |

23 tests / 49 assertions / ~0.35s on a cold run.

## Adding a new test

Put it under `tests/Unit/` (no DB) or `tests/Feature/` (DB + Eloquent). Pest auto-discovers via `tests/Pest.php`.

Quick template:

```php
<?php

use Hassam\DataAccessWrapper\BaseCache;

it('does the thing', function () {
    $cache = new BaseCache('-things-', 'v1');

    $cache->put('k', 'v');

    expect($cache->get('k'))->toBe('v');
});
```

For model tests, reuse `tests/Fixtures/Order.php` or add a new fixture there. If the new fixture needs a new table, add the schema to `TestCase::setUpSchema()`.

## CI tip

```yaml
# .github/workflows/tests.yml
name: tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: [8.2, 8.3, 8.4]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - run: composer install --no-interaction --no-progress
      - run: vendor/bin/pest
```

## Troubleshooting

**`Class "Hassam\DataAccessWrapper\Tests\TestCase" not found`** — run `composer dump-autoload`. The `autoload-dev` PSR-4 map needs to be regenerated.

**`Cache store does not support tagging`** — your local env override forced a non-tag store (`file`/`database`). `phpunit.xml` already pins `CACHE_DRIVER=array`; check no `.env` is loaded by accident.

**`SQLSTATE[HY000]: General error: 1 no such table: orders`** — `setUpSchema()` not running. Most likely a custom `setUp()` in a test file forgot `parent::setUp()`. Always call parent.

**Pest plugin install rejected** — composer needs permission. `composer.json` already declares `allow-plugins.pestphp/pest-plugin: true`. Re-run `composer install`.

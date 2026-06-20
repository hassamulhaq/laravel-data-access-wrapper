<?php

/*
|--------------------------------------------------------------------------
| Entity Cache Versions
|--------------------------------------------------------------------------
|
| Each key MUST match a model's cacheTag() — which defaults to the table
| name (e.g. Product => "products"). The value is the version segment baked
| into every cache key: {tenant}:{tag}:{version}:{column}:{value}.
|
| Bump a value ('v1' -> 'v2') to instantly orphan every cache entry for that
| model. Old keys are never read again and expire naturally via TTL — no
| cache scan, no flush command needed.
|
| Add a row for each model that extends Hassam\DataAccessWrapper\BaseModel.
| A missing key falls back to 'v1'.
|
*/

return [
    // 'products'   => 'v1',
    // 'categories' => 'v1',
];

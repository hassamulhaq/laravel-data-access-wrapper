<?php

use Hassam\DataAccessWrapper\BaseCache;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('namespaces keys with version + prefix', function () {
    $cache = new BaseCache('-orders-', 'v1');

    $cache->put('42', 'payload');

    expect($cache->getKey('42'))->toBe('prod:1v1-orders-42');
    expect(Cache::get('prod:1v1-orders-42'))->toBe('payload');
});

it('remember stores callback result and reuses it on second call', function () {
    $cache = new BaseCache('-orders-', 'v1');
    $hits = 0;

    $first = $cache->remember('list', function () use (&$hits) {
        $hits++;
        return ['a', 'b'];
    });
    $second = $cache->remember('list', function () use (&$hits) {
        $hits++;
        return ['c', 'd'];
    });

    expect($first)->toBe(['a', 'b']);
    expect($second)->toBe(['a', 'b']);
    expect($hits)->toBe(1);
});

it('remember caches falsy non-null values (regression)', function () {
    $cache = new BaseCache('-orders-', 'v1');
    $hits = 0;

    $cb = function () use (&$hits) {
        $hits++;
        return 0;
    };

    $cache->remember('count', $cb);
    $cache->remember('count', $cb);

    expect($hits)->toBe(1);
});

it('forget removes namespaced key only', function () {
    $cache = new BaseCache('-orders-', 'v1');
    $cache->put('42', 'x');
    Cache::put('raw-key', 'y');

    $cache->forget('42');

    expect($cache->get('42'))->toBeNull();
    expect(Cache::get('raw-key'))->toBe('y');
});

it('version bump invalidates old keys', function () {
    $v1 = new BaseCache('-orders-', 'v1');
    $v2 = new BaseCache('-orders-', 'v2');

    $v1->put('42', 'old');

    expect($v1->get('42'))->toBe('old');
    expect($v2->get('42'))->toBeNull();
});

it('tags returns new instance and does not mutate original', function () {
    $base = new BaseCache('-orders-', 'v1');
    $tagged = $base->tags('customer:5');

    expect($base->hasTags())->toBeFalse();
    expect($tagged->hasTags())->toBeTrue();
    expect($tagged->getTags())->toBe(['customer:5']);
    expect($base)->not->toBe($tagged);
});

it('tagged put/get uses tagged store; untagged read does not see it', function () {
    $base = new BaseCache('-orders-', 'v1');
    $tagged = $base->tags('customer:5');

    $tagged->put('list', ['a', 'b']);

    expect($tagged->get('list'))->toBe(['a', 'b']);
    expect($base->get('list'))->toBeNull();
});

it('flush wipes every key under tag', function () {
    $cache = (new BaseCache('-orders-', 'v1'))->tags('customer:5');
    $cache->put('list', ['a']);
    $cache->put('total', 99);

    $cache->flush();

    expect($cache->get('list'))->toBeNull();
    expect($cache->get('total'))->toBeNull();
});

it('flush without tags throws to prevent global wipe', function () {
    $cache = new BaseCache('-orders-', 'v1');

    expect(fn () => $cache->flush())
        ->toThrow(BadMethodCallException::class);
});

it('multi-tag flush wipes union of tags', function () {
    $a = (new BaseCache('-orders-', 'v1'))->tags('a');
    $b = (new BaseCache('-orders-', 'v1'))->tags('b');

    $a->put('k', 1);
    $b->put('k', 2);

    (new BaseCache('-orders-', 'v1'))->tags(['a', 'b'])->flush();

    expect($a->get('k'))->toBeNull();
    expect($b->get('k'))->toBeNull();
});

it('WithoutPrefix bypasses prefix and tags', function () {
    $cache = (new BaseCache('-orders-', 'v1'))->tags('customer:5');

    $cache->putWithoutPrefix('raw', 'value');

    expect(Cache::get('raw'))->toBe('value');
    expect($cache->getWithoutPrefix('raw'))->toBe('value');
});

it('pull reads then forgets', function () {
    $cache = new BaseCache('-orders-', 'v1');
    $cache->put('once', 'gone-after');

    expect($cache->pull('once'))->toBe('gone-after');
    expect($cache->get('once'))->toBeNull();
});

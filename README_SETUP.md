# Setup — installing this package in another Laravel project

Three ways to consume `hassam/data-access-wrapper`. Pick by audience:

| You are | Use |
|---|---|
| Solo dev iterating on the package locally | [Path repository](#1-local-path-repository) |
| **Distributing as zip — no GitHub auth needed** | [**Zip distribution**](#1b-zip-distribution-no-github-auth) |
| Team / CI installing from GitHub | [VCS repository](#2-vcs-repository-private-github) |
| Org with Private Packagist or self-hosted Satis | [Private Packagist](#3-private-packagist--satis) |

The package is at `/var/www/html/laravel-data-access-wrapper`. Remote: `git@github.com:hassamulhaq/laravel-data-access-wrapper.git` (private).

Target project for examples: `/var/www/html/rms-backend` (Laravel 13).

---

## 1. Local path repository

Best for active development — every change in the package is picked up by the consumer with no `composer update`. Ideal solo workflow.

### One-time setup in the consumer (`rms-backend`)

```bash
cd /var/www/html/rms-backend
```

Add the path repo + the require entry to `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-data-access-wrapper",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "hassam/data-access-wrapper": "*"
    }
}
```

Install:

```bash
composer require hassam/data-access-wrapper:*
```

Composer creates a **symlink** at `vendor/hassam/data-access-wrapper` → the source folder. Edits to package files are live.

### Pros / cons

✅ Instant feedback loop. No re-install on every package change.
✅ No git push needed during development.
❌ Other devs can't use this (path is local).
❌ CI can't use this (path doesn't exist on CI runner).

> **Don't commit the `repositories` block to a shared branch** unless every dev has the same path. For a shared repo, use VCS (next section) and keep the path repo only on your local feature branches, or guard it behind `composer.local.json` (Composer reads it if present and merges).

---

## 1b. Zip distribution (no GitHub auth)

Hand-deliver the package as a zip. Devs extract to a fixed path on their machine. Composer reads from that path — **no GitHub auth, no PAT, no SSH key**.

### Building the zip (package author)

```bash
cd /var/www/html/laravel-data-access-wrapper
git archive --format=zip \
  --prefix=laravel-data-access-wrapper/ \
  -o ~/laravel-data-access-wrapper-v0.1.0.zip HEAD
```

`git archive` exports **only tracked files** — no `.git`, `vendor/`, `.idea/`, `node_modules`. Output ~36 KB.

Distribute the zip via Slack, S3, internal share, etc.

### Consuming the zip (every dev)

```bash
# 1. Extract zip to standard path (matches the path-repo URL in composer.json)
sudo unzip -d /var/www/html/ ~/Downloads/laravel-data-access-wrapper-v0.1.0.zip
sudo chown -R $USER:$USER /var/www/html/laravel-data-access-wrapper

# 2. Install in consumer
cd /var/www/html/rms-backend
composer install
```

Consumer's `composer.json` already has:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-data-access-wrapper",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "hassam/data-access-wrapper": "*"
    }
}
```

Composer symlinks `vendor/hassam/data-access-wrapper` → `/var/www/html/laravel-data-access-wrapper`. Done. No auth.

### Updating

Author rebuilds zip, redistributes:

```bash
git archive --format=zip --prefix=laravel-data-access-wrapper/ -o ~/laravel-data-access-wrapper-v0.2.0.zip HEAD
```

Devs replace contents:

```bash
rm -rf /var/www/html/laravel-data-access-wrapper
sudo unzip -d /var/www/html/ ~/Downloads/laravel-data-access-wrapper-v0.2.0.zip
sudo chown -R $USER:$USER /var/www/html/laravel-data-access-wrapper

cd /var/www/html/rms-backend
composer update hassam/data-access-wrapper
php artisan config:clear
```

### Pros / cons

✅ No GitHub access required.
✅ No tokens, SSH keys, or PATs.
✅ Works offline once zip distributed.
✅ Symlink — code changes in the package dir are live.
❌ Manual rezip + redistribute on every release.
❌ Path must match exactly across all devs (`/var/www/html/laravel-data-access-wrapper`).
❌ CI runners need the zip pre-extracted in their image, or fall back to VCS for CI only.

> **Required path for `rms-backend` consumer:** `/var/www/html/laravel-data-access-wrapper` (because consumer references `../laravel-data-access-wrapper` relative to `/var/www/html/rms-backend`). If devs put the package elsewhere, update the path in `composer.json` and re-run `composer update`.

### Onboarding checklist (zip flow)

1. Receive zip from author.
2. `sudo unzip -d /var/www/html/ <zip>`.
3. `sudo chown -R $USER:$USER /var/www/html/laravel-data-access-wrapper`.
4. `cd /var/www/html/rms-backend && composer install`.
5. `php artisan vendor:publish --provider="Hassam\DataAccessWrapper\DataAccessWrapperServiceProvider"` (one-time).
6. Confirm `CACHE_STORE=redis` in `.env`.
7. `php artisan config:clear`.

---

## 2. VCS repository (private GitHub)

What you ship to your team and CI. Composer pulls the package directly from GitHub via SSH (preferred) or HTTPS + token.

### 2a. SSH — recommended for devs

Each dev needs an SSH key registered on GitHub with access to the package repo.

```bash
# Verify access:
ssh -T git@github.com
# Hi <user>! You've successfully authenticated...
```

In `rms-backend/composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:hassamulhaq/laravel-data-access-wrapper.git"
        }
    ],
    "require": {
        "hassam/data-access-wrapper": "^0.1"
    }
}
```

> Use a real version constraint (`^0.1`, `^1.0`, etc). Tag releases in the package: `git tag v0.1.0 && git push origin v0.1.0`.
> Until you tag, point at the `main` branch with `"dev-main"` and `"minimum-stability": "dev"`.

Install:

```bash
composer require hassam/data-access-wrapper:^0.1
# or while no tags exist:
composer require hassam/data-access-wrapper:dev-main
```

### 2b. HTTPS + Personal Access Token — for CI

GitHub PAT (fine-grained, repo-scoped, **read-only contents**) stored as a secret.

```bash
composer config --global --auth github-oauth.github.com <PAT>
```

For CI (GitHub Actions example):

```yaml
- name: Authenticate Composer to GitHub
  run: composer config --global --auth github-oauth.github.com ${{ secrets.PACKAGE_READ_PAT }}

- name: Install dependencies
  run: composer install --no-interaction --no-progress --prefer-dist
```

`composer.json` is the same as 2a — composer picks up the auth token automatically.

### 2c. Tagging and consuming versions

```bash
# In the package repo:
cd /var/www/html/laravel-data-access-wrapper
git tag v0.1.0
git push origin v0.1.0
```

Now consumers can pin: `"hassam/data-access-wrapper": "^0.1.0"`.

Bump versions per [SemVer](https://semver.org/): patch for bug fix, minor for additive feature, major for breaking change.

### Pros / cons

✅ Works for everyone with repo access.
✅ Reproducible builds via tags.
✅ Composer caches the package — fast installs.
❌ Every change needs a commit + push to be picked up by consumers.

---

## 3. Private Packagist / Satis

If your org already runs Private Packagist, Satis, or Repman, register the package there and add the repo URL once. Same `composer require` command afterwards.

```json
{
    "repositories": [
        { "type": "composer", "url": "https://repo.packagist.com/<org>/" }
    ],
    "require": {
        "hassam/data-access-wrapper": "^0.1"
    }
}
```

Auth via `auth.json` or env (Private Packagist gives the snippet). CI follows the same pattern as 2b.

---

## Combining 1 + 2 (best of both)

Keep VCS in committed `composer.json`. Override locally with a path repo via `composer.local.json` — Composer merges it automatically when present. Add `composer.local.json` to `.gitignore`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-data-access-wrapper",
            "options": { "symlink": true }
        }
    ]
}
```

Run `composer update hassam/data-access-wrapper` once. You get live edits locally; team and CI still pull from GitHub.

---

## After install — every consumer does this once

### 4.1. Publish configs

```bash
php artisan vendor:publish --provider="Hassam\DataAccessWrapper\DataAccessWrapperServiceProvider"
```

Publishes:

- `config/data-access-wrapper.php` — package settings.
- `config/cache-versions.php` — per-table cache version map. **Add an entry for every cacheable table.**
- `config/database.php` — only if you don't already have a `mongodb` connection block (skip otherwise).

### 4.2. Register table versions

In `config/cache-versions.php`:

```php
return [
    'version' => [
        'orders'      => 'v1',
        'order_items' => 'v1',
        'customers'   => 'v1',
        // every table that uses the Cacheable trait
    ],
];
```

Bump `v1 → v2` to invalidate that table's entire cache without `cache:clear`.

### 4.3. Use a tag-aware cache driver

The trait flushes via `Cache::tags(...)`. **Required driver**: `redis`, `memcached`, `array`, or `dynamodb`. The `file` and `database` drivers throw on tag operations.

```env
CACHE_DRIVER=redis
```

### 4.4. Smoke test

```bash
php artisan tinker
```

```php
>>> use Hassam\DataAccessWrapper\BaseCache;
>>> $c = new BaseCache('-test-', 'v1');
>>> $c->put('x', 'hello');
=> true
>>> $c->get('x');
=> "hello"
>>> $c->getKey('x');
=> "prod:1v1-test-x"
```

If those four lines all work, install is healthy.

---

## Onboarding checklist for a new dev

1. Clone `rms-backend`.
2. Ensure GitHub SSH key has read access to the package repo (`ssh -T git@github.com`).
3. `composer install`.
4. Copy `.env`, set `CACHE_DRIVER=redis` (or `memcached`).
5. `php artisan vendor:publish --provider="Hassam\DataAccessWrapper\DataAccessWrapperServiceProvider"` — only if `config/cache-versions.php` not committed yet.
6. `php artisan config:cache && php artisan optimize`.
7. Smoke-test (4.4).

---

## Updating the package version in consumers

After a new tag is pushed:

```bash
cd /var/www/html/rms-backend
composer update hassam/data-access-wrapper
```

To inspect what's installed:

```bash
composer show hassam/data-access-wrapper
```

To pin major/minor/patch ranges:

| Constraint | Allows |
|---|---|
| `^0.1` | 0.1.x and 0.x going forward, no 1.0 |
| `^1.0` | 1.x.x, no 2.0 |
| `~1.2.3` | 1.2.x only |
| `dev-main` | latest commit on main (unstable) |

---

## Troubleshooting

**`Could not authenticate against github.com`** — SSH key not loaded or PAT not set. `ssh-add ~/.ssh/id_ed25519` or `composer config --global --auth github-oauth.github.com <PAT>`.

**`The "../laravel-data-access-wrapper" directory does not exist`** (path repo) — relative path is wrong. From `rms-backend/composer.json` perspective, `../laravel-data-access-wrapper` resolves to `/var/www/html/laravel-data-access-wrapper`. Verify with `ls ../laravel-data-access-wrapper`.

**`Class "Hassam\DataAccessWrapper\BaseCache" not found`** — `composer dump-autoload`. PSR-4 map didn't refresh.

**`Cache store does not support tagging`** — driver is `file` or `database`. Switch to `redis` / `memcached` / `array`.

**`vendor:publish` overwrote my `config/cache.php`** — happens if you have a stale `cache.php` from an older version of this package. The current version publishes `cache-versions.php` instead. Delete the wrong file and re-publish.

**Path repo edits not appearing** — `"symlink": true` not set, or you're on Windows where symlinks need elevated perms. Re-run `composer update hassam/data-access-wrapper` once after fixing.

**Tagged version not found** — `composer update` with `--with-all-dependencies` to refresh metadata. Check `composer show hassam/data-access-wrapper --all` to see versions composer is aware of.

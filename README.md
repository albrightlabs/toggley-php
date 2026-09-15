# Toggley for PHP and Laravel

Feature flags from [Toggley](https://toggleyapp.com), evaluated in your PHP process from a cached copy of your environment's config. Checking a flag makes no network call, and user data never leaves your server.

```sh
composer require albrightlabs/toggley-php
```

Requires PHP 8.1+. Laravel 10, 11 and 12 are supported out of the box; plain PHP needs any PSR-16 cache.

## Laravel

```dotenv
TOGGLEY_SERVER_KEY=tgly_srv_...
```

```php
use Toggley\Laravel\Facades\Toggley;

if (Toggley::isEnabled('new-checkout')) {
    // on for the signed-in user
}

$theme = Toggley::string('theme', 'light');
$limit = Toggley::number('upload-limit-mb', 10);
```

```blade
@toggley('new-checkout')
    <x-new-checkout />
@else
    <x-checkout />
@endtoggley
```

(`@feature` works too when Laravel Pennant isn't installed.)

```php
Route::get('/beta', BetaController::class)->middleware('toggley:beta-dashboard'); // 404 when off
```

With no context argument the signed-in user's id is the context key. To add attributes for rules, set `context_resolver` after publishing the config (`php artisan vendor:publish --tag=toggley-config`):

```php
'context_resolver' => fn ($user) => $user ? ['key' => (string) $user->id, 'email' => $user->email, 'plan' => $user->plan] : null,
```

Tests: `Toggley::fake(['new-checkout' => true]);`

Commands: `toggley:check` (connection and values), `toggley:sync [--out=file]` (refresh the cache now, optionally write a bootstrap file), `toggley:flush-events`.

## Plain PHP

```php
$toggley = new \Toggley\Client(getenv('TOGGLEY_SERVER_KEY'), cache: $psr16Cache);

$context = ['key' => (string) $user->id, 'email' => $user->email];

if ($toggley->boolVariation('new-checkout', $context, false)) {
    // ...
}
```

Getters: `boolVariation`, `stringVariation`, `numberVariation`, `jsonVariation`, `variation`, the `...Detail` versions (value, variation, reason, assignment, flagVersion), `isEnabled($flag, $context)` and `allFlags($context)`. They never throw; on any problem you get your default and `reason.kind` is `ERROR`.

Options (third argument): `base_url`, `poll_interval` (seconds, default 30), `timeout` (default 2), `bootstrap` (config array or file path), `offline`, `events`, `logger` (callable).

## How it works under PHP-FPM

- The config is read from your cache. When it's older than `poll_interval`, the SDK checks for changes **after** the response is sent (`fastcgi_finish_request`, ETag, one request at a time via a cache lock). Only an empty cache makes a request during the page.
- If Toggley can't be reached, the last good config keeps working for up to 7 days, then the bootstrap config, then your defaults.
- Evaluation counts (never contexts) are added to the cache and sent at most once a minute. Without a cache, counts aren't sent from PHP-FPM.

Use a cache shared by your servers (Redis, Memcached, database) so they agree on the config and share the work.

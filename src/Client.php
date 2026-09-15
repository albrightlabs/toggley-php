<?php

namespace Toggley;

use Psr\SimpleCache\CacheInterface;
use Toggley\Engine\Evaluator;
use Toggley\Engine\NativeRandom;
use Toggley\Engine\RandomSource;
use Toggley\Transport\HttpTransport;
use Toggley\Transport\Transport;

/**
 * Client evaluates Toggley flags in-process from the environment's config.
 *
 * Built for PHP-FPM, where every request starts fresh:
 * - With a PSR-16 cache the config is read from the cache (no network call).
 *   When it is older than poll_interval it is revalidated after the response
 *   (ETag, under a short lock). Only an empty cache fetches during the request.
 * - Evaluation counts are added to the cache and sent at most once a minute.
 *   Without a cache under PHP-FPM, counts are not sent.
 * - If Toggley can't be reached the last good config keeps serving, then the
 *   bootstrap config, then the defaults you pass.
 *
 * Getters never throw.
 */
final class Client
{
    public const SDK_NAME = 'toggley-php';
    public const SDK_VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://my.toggleyapp.com';

    private array $options;
    private ?array $document = null;
    private ?string $etag = null;
    private ?float $fetchedAt = null;
    private bool $loaded = false;
    private bool $keyRejected = false;
    private Summarizer $summarizer;
    private Transport $transport;
    private RandomSource $random;
    private \Closure $clock;
    private bool $terminated = false;

    /**
     * @param array{
     *     base_url?: string, poll_interval?: int, timeout?: float, bootstrap?: array|string|null,
     *     offline?: bool, events?: bool, flush_interval?: int, stale_ttl?: int, logger?: callable|null,
     *     auto_terminate?: bool, clock?: callable, random?: RandomSource, sapi?: string
     * } $options
     */
    public function __construct(
        private readonly string $serverKey = '',
        private readonly ?CacheInterface $cache = null,
        array $options = [],
        ?Transport $transport = null,
    ) {
        if (str_starts_with($serverKey, 'tgly_cli_')) {
            throw new ToggleyException('This is a client key. Server SDKs need the server key (tgly_srv_...) from the environment page.', 'wrong_key_type');
        }
        if (empty($options['offline']) && !str_starts_with($serverKey, 'tgly_srv_')) {
            throw new ToggleyException('A server key (tgly_srv_...) is required. Set offline to run from a bootstrap config only.', 'missing_key');
        }

        $this->options = $options + [
            'base_url' => self::DEFAULT_BASE_URL,
            'poll_interval' => 30,
            'timeout' => 2.0,
            'bootstrap' => null,
            'offline' => false,
            'events' => true,
            'flush_interval' => 60,
            'stale_ttl' => 7 * 86400,
            'logger' => null,
            'auto_terminate' => true,
            'sapi' => PHP_SAPI,
        ];
        $this->options['base_url'] = rtrim($this->options['base_url'], '/');
        $this->transport = $transport ?? new HttpTransport;
        $this->random = $options['random'] ?? new NativeRandom;
        $this->clock = \Closure::fromCallable($options['clock'] ?? fn () => microtime(true));
        $this->summarizer = new Summarizer($this->clock);

        if ($this->options['events'] && $this->cache === null && $this->options['sapi'] !== 'cli') {
            // Without a cache there is nowhere to collect counts across PHP-FPM requests.
            $this->options['events'] = false;
        }

        if ($this->options['auto_terminate']) {
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $this->terminate();
            });
        }
    }

    public function isEnabled(string $flagKey, ?array $context = null): bool
    {
        return $this->boolVariation($flagKey, $context, false);
    }

    public function variationDetail(string $flagKey, ?array $context, mixed $default = null): array
    {
        try {
            $document = $this->document();
            $result = $document === null
                ? ['value' => $default, 'variation' => null, 'reason' => ['kind' => 'ERROR', 'errorKind' => 'CLIENT_NOT_READY'], 'assignment' => null, 'flagVersion' => null]
                : Evaluator::evaluate($document, $flagKey, $context, $default, $this->random);
        } catch (\Throwable $e) {
            $this->report("Evaluation failed: {$e->getMessage()}");
            $result = ['value' => $default, 'variation' => null, 'reason' => ['kind' => 'ERROR', 'errorKind' => 'EXCEPTION'], 'assignment' => null, 'flagVersion' => null];
        }

        if ($this->options['events'] && !$this->options['offline']) {
            $this->summarizer->record($flagKey, $result);
        }

        return $result;
    }

    public function variation(string $flagKey, ?array $context, mixed $default = null): mixed
    {
        return $this->variationDetail($flagKey, $context, $default)['value'];
    }

    public function boolVariationDetail(string $flagKey, ?array $context, bool $default): array
    {
        return Evaluator::typed($this->variationDetail($flagKey, $context, $default), 'boolean', $default);
    }

    public function boolVariation(string $flagKey, ?array $context, bool $default = false): bool
    {
        return $this->boolVariationDetail($flagKey, $context, $default)['value'];
    }

    public function stringVariationDetail(string $flagKey, ?array $context, string $default): array
    {
        return Evaluator::typed($this->variationDetail($flagKey, $context, $default), 'string', $default);
    }

    public function stringVariation(string $flagKey, ?array $context, string $default): string
    {
        return $this->stringVariationDetail($flagKey, $context, $default)['value'];
    }

    public function numberVariationDetail(string $flagKey, ?array $context, int|float $default): array
    {
        return Evaluator::typed($this->variationDetail($flagKey, $context, $default), 'number', $default);
    }

    public function numberVariation(string $flagKey, ?array $context, int|float $default): int|float
    {
        return $this->numberVariationDetail($flagKey, $context, $default)['value'];
    }

    public function jsonVariationDetail(string $flagKey, ?array $context, mixed $default): array
    {
        return Evaluator::typed($this->variationDetail($flagKey, $context, $default), 'json', $default);
    }

    public function jsonVariation(string $flagKey, ?array $context, mixed $default): mixed
    {
        return $this->jsonVariationDetail($flagKey, $context, $default)['value'];
    }

    /** @return array<string, mixed> every flag's value for the context (not counted) */
    public function allFlags(?array $context): array
    {
        $document = $this->document();
        if ($document === null) {
            return [];
        }

        return array_map(fn ($result) => $result['value'], Evaluator::evaluateAll($document, $context, $this->random));
    }

    public function initialized(): bool
    {
        return $this->document() !== null;
    }

    /**
     * refresh fetches the config now (conditional on the ETag) and stores it.
     * Returns false when Toggley couldn't be reached or rejected the key.
     */
    public function refresh(): bool
    {
        if ($this->options['offline'] || $this->keyRejected) {
            return false;
        }

        $headers = $this->headers();
        if ($this->etag !== null) {
            $headers['If-None-Match'] = $this->etag;
        }

        try {
            $response = $this->transport->send('GET', "{$this->options['base_url']}/api/v2/sdk/config", $headers, null, (float) $this->options['timeout']);
        } catch (\Throwable $e) {
            $this->report($e->getMessage());
            return false;
        }

        if ($response->status === 304 && $this->document !== null) {
            $this->store($this->document, $this->etag);
            return true;
        }

        if ($response->status === 200) {
            $document = $response->json();
            if (!self::isConfigDocument($document)) {
                $this->report('Toggley sent a config this SDK version cannot read. Keeping the last good config.');
                return false;
            }
            $this->store($document, $response->header('etag'));
            return true;
        }

        if ($response->status === 401 || $response->status === 403) {
            $this->keyRejected = true;
            $this->report(($response->json()['error']['message'] ?? 'Toggley rejected the server key.') . " (HTTP {$response->status})");
            return false;
        }

        $this->report("Toggley returned HTTP {$response->status} for the config.");
        return false;
    }

    /**
     * terminate runs after the response: revalidates a stale config and sends or
     * stores evaluation counts. Called automatically at shutdown (and by Laravel).
     */
    public function terminate(): void
    {
        if ($this->terminated) {
            return;
        }
        $this->terminated = true;

        try {
            if ($this->loaded && !$this->options['offline'] && $this->isStale() && $this->acquire('refresh', 10)) {
                $this->refresh();
            }
            $this->persistEvents();
        } catch (\Throwable $e) {
            $this->report("Toggley background work failed: {$e->getMessage()}");
        }
    }

    /** flush sends all collected evaluation counts now (including those stored in the cache). */
    public function flush(): void
    {
        if (!$this->options['events'] || $this->options['offline']) {
            return;
        }

        if ($this->cache !== null) {
            $stored = $this->cache->get($this->cacheKey('events'));
            if (is_array($stored)) {
                $this->summarizer->merge($stored);
            }
            $this->cache->delete($this->cacheKey('events'));
        }

        $this->send($this->summarizer->snapshot(['name' => self::SDK_NAME, 'version' => self::SDK_VERSION]));
        $this->cache?->set($this->cacheKey('events_flushed'), ($this->clock)());
    }

    public static function isConfigDocument(mixed $document): bool
    {
        return is_array($document) && ($document['schemaVersion'] ?? null) === 1 && isset($document['flags']) && is_array($document['flags']);
    }

    private function document(): ?array
    {
        if ($this->loaded) {
            if ($this->cache === null && !$this->options['offline'] && $this->isStale()) {
                // Long-running processes without a cache refresh in place.
                $this->refresh();
            }
            return $this->document;
        }

        $this->loaded = true;

        if (!$this->options['offline'] && $this->cache !== null) {
            $cached = $this->cache->get($this->cacheKey('config'));
            if (is_array($cached) && self::isConfigDocument($cached['document'] ?? null)) {
                $this->document = $cached['document'];
                $this->etag = $cached['etag'] ?? null;
                $this->fetchedAt = (float) ($cached['fetchedAt'] ?? 0);
                return $this->document;
            }
        }

        if (!$this->options['offline'] && $this->refresh()) {
            return $this->document;
        }

        $this->document ??= $this->bootstrap();

        return $this->document;
    }

    private function bootstrap(): ?array
    {
        $bootstrap = $this->options['bootstrap'];
        if ($bootstrap === null) {
            return null;
        }

        $document = is_string($bootstrap) ? json_decode((string) @file_get_contents($bootstrap), true) : $bootstrap;
        if (!self::isConfigDocument($document)) {
            $this->report('The bootstrap config could not be read.');
            return null;
        }

        return $document;
    }

    private function store(array $document, ?string $etag): void
    {
        $this->document = $document;
        $this->etag = $etag;
        $this->fetchedAt = ($this->clock)();
        $this->cache?->set($this->cacheKey('config'), ['document' => $document, 'etag' => $etag, 'fetchedAt' => $this->fetchedAt], (int) $this->options['stale_ttl']);
    }

    private function isStale(): bool
    {
        return $this->fetchedAt === null || ($this->clock)() - $this->fetchedAt >= $this->options['poll_interval'];
    }

    private function persistEvents(): void
    {
        if (!$this->options['events'] || $this->options['offline']) {
            return;
        }

        if ($this->cache === null) {
            // CLI without a cache: send at the end of the process.
            $this->send($this->summarizer->snapshot(['name' => self::SDK_NAME, 'version' => self::SDK_VERSION]));
            return;
        }

        if (!$this->summarizer->isEmpty()) {
            $stored = $this->cache->get($this->cacheKey('events'));
            if (is_array($stored)) {
                $this->summarizer->merge($stored);
            }
            $this->cache->set($this->cacheKey('events'), $this->summarizer->toArray(), 3600);
            $this->summarizer = new Summarizer($this->clock);
        }

        $lastFlush = $this->cache->get($this->cacheKey('events_flushed'));
        if ($lastFlush === null) {
            $this->cache->set($this->cacheKey('events_flushed'), ($this->clock)());
        } elseif (($this->clock)() - (float) $lastFlush >= $this->options['flush_interval'] && $this->acquire('flush', 30)) {
            $this->flush();
        }
    }

    private function send(?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        try {
            $response = $this->transport->send('POST', "{$this->options['base_url']}/api/v2/sdk/events", $this->headers() + [
                'Content-Type' => 'application/json',
                'X-Toggley-Payload-Id' => bin2hex(random_bytes(16)),
            ], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), (float) $this->options['timeout']);

            if ($response->status >= 300) {
                $this->report("Toggley didn't accept evaluation counts (HTTP {$response->status}).");
            }
        } catch (\Throwable $e) {
            $this->report("Couldn't send evaluation counts: {$e->getMessage()}");
        }
    }

    /** acquire is a best-effort lock so concurrent requests don't all refresh at once. */
    private function acquire(string $name, int $seconds): bool
    {
        if ($this->cache === null) {
            return true;
        }

        $key = $this->cacheKey("lock_{$name}");
        if ($this->cache->has($key)) {
            return false;
        }

        return $this->cache->set($key, 1, $seconds);
    }

    private function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->serverKey}",
            'User-Agent' => self::SDK_NAME . '/' . self::SDK_VERSION,
            'Accept' => 'application/json',
        ];
    }

    private function cacheKey(string $name): string
    {
        return 'toggley_' . $name . '_' . substr(hash('sha256', $this->serverKey . '|' . $this->options['base_url']), 0, 16);
    }

    private function report(string $message): void
    {
        if ($this->options['logger']) {
            ($this->options['logger'])("[toggley] {$message}");
        }
    }
}

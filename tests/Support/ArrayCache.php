<?php

namespace Toggley\Tests\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * In-memory PSR-16 cache for tests. TTLs expire against the given clock (seconds).
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{0: mixed, 1: float|null}> key => [value, expires at] */
    public array $items = [];

    private \Closure $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = \Closure::fromCallable($clock ?? fn () => microtime(true));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->items[$key][0] : $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $seconds = $ttl instanceof \DateInterval ? (new \DateTime('@0'))->add($ttl)->getTimestamp() : $ttl;
        $this->items[$key] = [$value, $seconds === null ? null : ($this->clock)() + $seconds];
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->items = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get($key, $default);
        }
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->items)) {
            return false;
        }

        [, $expires] = $this->items[$key];
        if ($expires !== null && ($this->clock)() >= $expires) {
            unset($this->items[$key]);
            return false;
        }

        return true;
    }
}

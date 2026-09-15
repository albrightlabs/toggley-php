<?php

namespace Toggley\Laravel;

use Toggley\Client;

/**
 * ToggleyFake answers from fixed values, for tests: Toggley::fake(['new-checkout' => true]).
 */
class ToggleyFake extends Toggley
{
    public function __construct(private array $values = [])
    {
    }

    public function set(string $flag, mixed $value): static
    {
        $this->values[$flag] = $value;
        return $this;
    }

    public function client(): Client
    {
        throw new \LogicException('Toggley is faked; there is no client.');
    }

    public function isEnabled(string $flag, ?array $context = null): bool
    {
        return ($this->values[$flag] ?? false) === true;
    }

    public function variation(string $flag, mixed $default = null, ?array $context = null): mixed
    {
        return array_key_exists($flag, $this->values) ? $this->values[$flag] : $default;
    }

    public function string(string $flag, string $default, ?array $context = null): string
    {
        return is_string($this->values[$flag] ?? null) ? $this->values[$flag] : $default;
    }

    public function number(string $flag, int|float $default, ?array $context = null): int|float
    {
        return is_int($this->values[$flag] ?? null) || is_float($this->values[$flag] ?? null) ? $this->values[$flag] : $default;
    }

    public function json(string $flag, mixed $default, ?array $context = null): mixed
    {
        return $this->variation($flag, $default);
    }

    public function detail(string $flag, mixed $default = null, ?array $context = null): array
    {
        return ['value' => $this->variation($flag, $default), 'variation' => null, 'reason' => ['kind' => 'FAKE'], 'assignment' => null, 'flagVersion' => null];
    }

    public function all(?array $context = null): array
    {
        return $this->values;
    }

    public function defaultContext(): ?array
    {
        return null;
    }
}

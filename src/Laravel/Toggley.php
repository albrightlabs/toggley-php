<?php

namespace Toggley\Laravel;

use Illuminate\Contracts\Auth\Factory as Auth;
use Toggley\Client;

/**
 * Toggley is what the facade calls. When no context is passed it uses the
 * signed-in user (config toggley.context_resolver decides the attributes).
 */
class Toggley
{
    public function __construct(private readonly Client $client, private readonly ?Auth $auth = null, private $contextResolver = null)
    {
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function isEnabled(string $flag, ?array $context = null): bool
    {
        return $this->client->boolVariation($flag, $context ?? $this->defaultContext(), false);
    }

    public function isDisabled(string $flag, ?array $context = null): bool
    {
        return !$this->isEnabled($flag, $context);
    }

    public function variation(string $flag, mixed $default = null, ?array $context = null): mixed
    {
        return $this->client->variation($flag, $context ?? $this->defaultContext(), $default);
    }

    public function string(string $flag, string $default, ?array $context = null): string
    {
        return $this->client->stringVariation($flag, $context ?? $this->defaultContext(), $default);
    }

    public function number(string $flag, int|float $default, ?array $context = null): int|float
    {
        return $this->client->numberVariation($flag, $context ?? $this->defaultContext(), $default);
    }

    public function json(string $flag, mixed $default, ?array $context = null): mixed
    {
        return $this->client->jsonVariation($flag, $context ?? $this->defaultContext(), $default);
    }

    public function detail(string $flag, mixed $default = null, ?array $context = null): array
    {
        return $this->client->variationDetail($flag, $context ?? $this->defaultContext(), $default);
    }

    public function all(?array $context = null): array
    {
        return $this->client->allFlags($context ?? $this->defaultContext());
    }

    public function defaultContext(): ?array
    {
        $user = $this->auth?->guard()->user();

        if ($this->contextResolver) {
            return ($this->contextResolver)($user);
        }

        return $user ? ['key' => (string) $user->getAuthIdentifier()] : null;
    }
}

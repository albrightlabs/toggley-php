<?php

namespace Toggley\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Toggley\Laravel\ToggleyFake;

/**
 * @method static bool isEnabled(string $flag, ?array $context = null)
 * @method static bool isDisabled(string $flag, ?array $context = null)
 * @method static mixed variation(string $flag, mixed $default = null, ?array $context = null)
 * @method static string string(string $flag, string $default, ?array $context = null)
 * @method static int|float number(string $flag, int|float $default, ?array $context = null)
 * @method static mixed json(string $flag, mixed $default, ?array $context = null)
 * @method static array detail(string $flag, mixed $default = null, ?array $context = null)
 * @method static array all(?array $context = null)
 * @method static \Toggley\Client client()
 */
class Toggley extends Facade
{
    public static function fake(array $values = []): ToggleyFake
    {
        static::swap($fake = new ToggleyFake($values));
        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Toggley\Laravel\Toggley::class;
    }
}

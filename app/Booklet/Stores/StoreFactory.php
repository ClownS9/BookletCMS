<?php

namespace App\Booklet\Stores;

use App\Booklet\Schema\Definition;
use InvalidArgumentException;

class StoreFactory
{
    /**
     * The driver configured for a definition's group, e.g. "database".
     */
    public static function driverFor(Definition $definition): string
    {
        return config("booklet.drivers.{$definition->group}")
            ?: config('booklet.default_driver', 'database');
    }

    public static function make(Definition $definition, ?string $driver = null): Store
    {
        $driver ??= static::driverFor($definition);

        return match ($driver) {
            'database' => new DatabaseStore($definition),
            'file' => new FileStore($definition),
            default => class_exists($driver) && is_subclass_of($driver, Store::class)
                ? new $driver($definition)
                : throw new InvalidArgumentException("Booklet driver [{$driver}] for [{$definition->group}] isn't database, file, or a Store class."),
        };
    }
}

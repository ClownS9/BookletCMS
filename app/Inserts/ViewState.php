<?php

namespace App\Inserts;

use Illuminate\View\Factory;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Keeps a page's place while an insert draws something inside it.
 *
 * When a view fails, Laravel wipes the view factory's bookkeeping (which
 * component, section or loop the page was in) before passing the error on.
 * An insert's [[slip]] that fails half-way would then break the page around
 * it, even though the error is caught. So the bookkeeping is noted before
 * the slip draws, and put back if it fails: the page carries on without it.
 */
class ViewState
{
    /** @var ReflectionProperty[]|null */
    protected static ?array $properties = null;

    public static function protect(callable $draw): mixed
    {
        $factory = app('view');
        $saved = $factory instanceof Factory ? static::snapshot($factory) : null;

        try {
            return $draw();
        } catch (Throwable $e) {
            if ($saved !== null) {
                static::restore($factory, $saved);
            }
            throw $e;
        }
    }

    /** @return ReflectionProperty[] the factory's own lists and counters */
    protected static function properties(Factory $factory): array
    {
        if (static::$properties === null) {
            static::$properties = [];
            foreach ((new ReflectionClass($factory))->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                $property->setAccessible(true);
                $value = $property->getValue($factory);
                if (is_array($value) || is_int($value)) {
                    static::$properties[] = $property;
                }
            }
        }

        return static::$properties;
    }

    protected static function snapshot(Factory $factory): array
    {
        $saved = [];
        foreach (static::properties($factory) as $property) {
            $saved[$property->getName()] = $property->getValue($factory);
        }

        return $saved;
    }

    protected static function restore(Factory $factory, array $saved): void
    {
        foreach (static::properties($factory) as $property) {
            if (array_key_exists($property->getName(), $saved)) {
                $property->setValue($factory, $saved[$property->getName()]);
            }
        }
    }
}

<?php

namespace App\Support\Wix\Data;

use BackedEnum;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;

/** Closed typed mapping: no coercion, ignored keys, or inferred nullable defaults. */
abstract readonly class ContractData implements JsonSerializable
{
    /** @return array<string, class-string<ContractData>|'string'|'int'> */
    protected static function lists(): array
    {
        return [];
    }

    /** @return list<string> */
    protected static function maps(): array
    {
        return [];
    }

    /** @return list<string> */
    protected static function optional(): array
    {
        return [];
    }

    /** @param array<string, mixed> $data */
    final public static function fromArray(array $data): static
    {
        $class = new ReflectionClass(static::class);
        $parameters = $class->getConstructor()->getParameters();
        $names = array_map(fn ($parameter): string => $parameter->getName(), $parameters);
        ContractGuard::require(array_diff(array_keys($data), $names) === [], 'unknown '.static::class.' field');
        $values = [];
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            if (! array_key_exists($name, $data) && in_array($name, static::optional(), true)) {
                continue;
            }
            ContractGuard::require(array_key_exists($name, $data), 'required '.static::class.'.'.$name);
            $value = $data[$name];
            $type = $parameter->getType();
            if (array_key_exists($name, static::lists())) {
                ContractGuard::require(is_array($value) && (in_array($name, static::maps(), true) || array_is_list($value)), 'typed collection '.$name);
                $itemType = static::lists()[$name];
                $items = [];
                foreach ($value as $key => $item) {
                    if (in_array($name, static::maps(), true)) {
                        ContractGuard::require(is_string($key) && $key !== '', 'map string key');
                    }
                    if (is_subclass_of($itemType, self::class)) {
                        ContractGuard::require(is_array($item), 'DTO collection item');
                        $items[$key] = $itemType::fromArray($item);
                    } else {
                        ContractGuard::require(get_debug_type($item) === $itemType, 'literal collection item');
                        $items[$key] = $item;
                    }
                }
                $value = $items;
            } elseif ($value !== null && $type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $target = $type->getName();
                if (is_subclass_of($target, BackedEnum::class)) {
                    ContractGuard::require(is_string($value) && $target::tryFrom($value) !== null, 'closed enum '.$name);
                    $value = $target::from($value);
                } else {
                    ContractGuard::require(is_array($value) && is_subclass_of($target, self::class), 'nested DTO '.$name);
                    $value = $target::fromArray($value);
                }
            } else {
                $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
                $valid = $value === null && $type->allowsNull();
                foreach ($types as $member) {
                    $valid = $valid || get_debug_type($value) === $member->getName();
                }
                ContractGuard::require($valid, 'literal type '.$name);
            }
            $values[$name] = $value;
        }

        return new static(...$values);
    }

    /** @return array<string, mixed> */
    final public function toArray(): array
    {
        $encode = function (mixed $value) use (&$encode): mixed {
            if ($value instanceof self) {
                return $value->toArray();
            }
            if ($value instanceof BackedEnum) {
                return $value->value;
            }
            if (is_array($value)) {
                return array_map($encode, $value);
            }

            return $value;
        };
        $values = get_object_vars($this);
        foreach (static::optional() as $key) {
            if ($values[$key] === null) {
                unset($values[$key]);
            }
        }

        return array_map($encode, $values);
    }

    final public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    protected function validateLists(): void
    {
        foreach (static::lists() as $name => $type) {
            $items = $this->{$name};
            ContractGuard::require(in_array($name, static::maps(), true) || array_is_list($items), 'ordered list '.$name);
            foreach ($items as $key => $item) {
                ContractGuard::require(in_array($type, ['string', 'int'], true) ? get_debug_type($item) === $type : $item instanceof $type, 'typed list '.$name);
                if (in_array($name, static::maps(), true)) {
                    ContractGuard::require(is_string($key) && $key !== '', 'map string ID');
                }
            }
        }
    }
}

<?php

namespace Tey\Concierge\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Page props: constructor-promoted public properties, serialized in declaration
 * order with nested props (and lists of them) turned into arrays as well.
 *
 * @implements Arrayable<string, mixed>
 */
abstract readonly class Props implements Arrayable, JsonSerializable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_map(static::serialize(...), get_object_vars($this));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    protected static function serialize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Arrayable => $value->toArray(),
            is_array($value) => array_map(static::serialize(...), $value),
            default => $value,
        };
    }
}

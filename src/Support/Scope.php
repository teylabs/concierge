<?php

namespace Tey\Concierge\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One record an invitation or membership is scoped to: its morph type (the
 * morph map alias when the app has one, as Relation::getMorphAlias gives) and
 * its key, kept as a string so integer, UUID and ULID keys compare alike.
 */
final readonly class Scope
{
    public function __construct(public string $type, public string $id)
    {
        if ($type === '' || $id === '' || mb_strlen($type) > 255 || mb_strlen($id) > 255) {
            throw new InvalidArgumentException('A scope needs a type and an id of 1 to 255 characters.');
        }
    }

    public static function of(Model|self $record): self
    {
        if ($record instanceof self) {
            return $record;
        }

        $key = $record->getKey();

        if ($key === null || ! $record->exists) {
            throw new InvalidArgumentException('A scope needs a saved record.');
        }

        return new self($record->getMorphClass(), (string) $key);
    }

    public function is(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    /** @return array{type: string, id: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}

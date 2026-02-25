<?php

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-present David Cole <david.cole1340@gmail.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Helpers;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

interface CollectionInterface extends ArrayAccess, JsonSerializable, IteratorAggregate, Countable
{
    public function get(string $discrim, $key);
    public function set($offset, $value);
    public function pull($key, $default = null);
    public function shift();
    public function fill($items): self;
    public function push(...$items): self;
    public function pushItem($item): self;
    public function count(): int;
    public function first();
    public function last();
    public function isset($offset): bool;
    public function has(...$keys): bool;
    public function search(mixed $needle, bool $strict = false): string|int|false;
    public function filter(callable $callback): CollectionInterface;
    public function find(callable $callback);
    public function find_key(callable $callback);
    public function any(callable $callback): bool;
    public function all(callable $callback): bool;
    public function clear(): void;
    public function splice(int $offset, ?int $length, mixed $replacement = []): self;
    public function slice(int $offset, ?int $length = null, bool $preserve_keys = false): CollectionInterface;
    public function sort(callable|int|null $callback): CollectionInterface;
    public function diff($items, ?callable $callback = null): CollectionInterface;
    public function intersect($items, ?callable $callback = null): CollectionInterface;
    public function walk(callable $callback, mixed $arg): CollectionInterface;
    public function reduce(callable $callback, $initial = null): CollectionInterface;
    public function map(callable $callback): CollectionInterface;
    public function unique(int $flags = SORT_STRING): CollectionInterface;
    public function merge($collection): self;
    public function collect(): CollectionInterface;
    public function keys(): array;
    public function values(): array;
    public function toArray();
    public function offsetExists($offset): bool;
    #[\ReturnTypeWillChange]
    public function offsetGet($offset);
    public function offsetSet($offset, $value): void;
    public function offsetUnset($offset): void;
    public function serialize(int $flags = 0, ?int $depth = 512): string;
    public function __serialize(): array;
    public function unserialize(string $serialized): void;
    public function __unserialize($data): void;
    public function jsonSerialize(): array;
    public function getIterator(): Traversable;
    public function __debugInfo(): array;
}

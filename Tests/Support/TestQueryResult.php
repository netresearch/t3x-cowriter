<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Support;

use RuntimeException;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Test implementation of QueryResultInterface for use in unit and integration tests.
 *
 * This class provides a simple, iterable query result that wraps an array of items.
 * It eliminates the need for complex mocking of QueryResultInterface in tests.
 *
 * @template T of object
 *
 * @implements QueryResultInterface<T>
 */
final class TestQueryResult implements QueryResultInterface
{
    private int $position = 0;

    /**
     * @param array<T> $items
     */
    public function __construct(
        private readonly array $items,
    ) {}

    public function setQuery(QueryInterface $query): void
    {
        // Not needed for testing
    }

    public function getQuery(): QueryInterface
    {
        throw new RuntimeException('Not implemented in test stub');
    }

    public function getFirst(): ?object
    {
        return $this->items[0] ?? null;
    }

    /**
     * @return array<T>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function current(): mixed
    {
        return $this->items[$this->position] ?? null;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function key(): int
    {
        return $this->position;
    }

    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Immutable for testing
    }

    public function offsetUnset(mixed $offset): void
    {
        // Immutable for testing
    }
}

<?php

namespace Jsl\Database\Collections;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;

class Collection implements ArrayAccess, Countable, JsonSerializable, IteratorAggregate
{
    /**
     * @var array
     */
    protected $items = [];

    /**
     * @var string|null
     */
    protected ?string $modelClass = null;

    /**
     * @var object|array|string|null
     */
    protected object|array|string|null $transformer = null;

    /**
     * @param array $data
     */
    public function __construct(array $data = [], ?string $modelClass = null, ?callable $transformer = null)
    {
        $this->modelClass = $modelClass;
        $this->transformer = $transformer;

        if ($data) {
            foreach ($data as $key => $item) {
                $this->exceptionOnInvalidType($item, true);
                $this->offsetSet($key, $this->prepareItem($item));
            }
        }
    }


    /**
     * If needed, convert the value into models
     *
     * @param array|object $item
     *
     * @return mixed
     */
    protected function prepareItem(array|object &$item): mixed
    {
        if (is_array($item) && $this->modelClass) {
            $class = $this->modelClass;
            return new $class($item, $this->transformer);
        }

        return $item;
    }


    /**
     * Add an item to the collection
     *
     * @param int|string $offset
     * @param array|object $item
     * 
     * @return void
     */
    public function offsetSet($offset, $item): void
    {
        $this->exceptionOnInvalidType($item);
        $item = $this->prepareItem($item);

        if (is_null($offset)) {
            $this->items[] = $item;
        } else {
            $this->items[$offset] = $item;
        }
    }


    /**
     * Check if an index exists
     *
     * @param  string $offset
     *
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }


    /**
     * Unset an item
     *
     * @param  int|string $offset
     * 
     * @return void
     */
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }


    /**
     * Get an item from the collection
     *
     * @param  int|string $offset
     *
     * @return array|object|null
     */
    public function offsetGet($offset): mixed
    {
        return isset($this->items[$offset]) ? $this->items[$offset] : null;
    }


    /**
     * Get the current item count
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }


    /**
     * Return the collection as a standard array
     *
     * @return array
     */
    public function asArray(): array
    {
        return $this->items;
    }


    /**
     * Get the first item in the collection
     *
     * @return array|object|null
     */
    public function first(): array|object|null
    {
        return reset($this->items);
    }


    /**
     * Get the last item in the collection
     *
     * @return array|object|null
     */
    public function last(): array|object|null
    {
        return end($this->items);
    }


    /**
     * Get a list of a specific value from all items
     *
     * @see https://www.php.net/manual/en/function.array-column.php
     * 
     * @param int|string|null $columnKey
     * @param int|string|null|null $indexKey
     *
     * @return array
     */
    public function column(int|string|null $columnKey, int|string|null $indexKey = null): array
    {
        return array_column($this->items, $columnKey, $indexKey);
    }


    /**
     * Sort the collection
     *
     * @param  string $property
     * @param  string $direction
     *
     * @param self
     */
    public function usort(Closure $sortCallback): self
    {
        usort($this->items, $sortCallback);

        return $this;
    }


    /**
     * Remove an item fromt the collection
     *
     * @param  string $key
     * 
     * @return self
     */
    public function unset($key): self
    {
        if (array_key_exists($key, $this->items)) {
            unset($this->items[$key]);
        }

        return $this;
    }


    /**
     * Check if a key is set
     *
     * @param  string  $key
     * @return boolean
     */
    public function __isset(string $key): bool
    {
        return isset($this->items[$key]);
    }

    /**
     * Return the collection as json
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return $this->items;
    }


    /**
     * Return the iterator
     *
     * @return ArrayIterator
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }


    /**
     * Call a function on each item in the collection
     *
     * @param callable $callback
     *
     * @return self
     */
    public function onEach(callable $callback): self
    {
        foreach ($this->items as $index => $item) {
            $this->items[$index] = call_user_func_array($callback, [$item, $index]);
        }

        return $this;
    }


    /**
     * Validate the item type
     *
     * @param  mixed $item
     * 
     * @return void
     *
     * @throws InvalidArgumentException if the data is of the wrong type
     */
    protected function exceptionOnInvalidType(&$value, bool $allowArray = false): void
    {
        if (is_array($value) === false && is_object($value) === false) {
            throw new InvalidArgumentException("Collections only support arrays and objects");
        }

        if (is_object($value) && $this->modelClass && $value instanceof $this->modelClass === false) {
            throw new InvalidArgumentException("Only object of type {$this->modelClass} can be added to this collection");
        }
    }
}

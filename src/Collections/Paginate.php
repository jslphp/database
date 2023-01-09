<?php

namespace Jsl\Database\Collections;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use Exception;
use IteratorAggregate;
use Jsl\Database\Query\Builder;
use JsonSerializable;

class Paginate implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Current page
     * @var int
     */
    protected int $page = 1;

    /**
     * Total page count
     * @var int
     */
    protected int $pageCount = 0;

    /**
     * Items per page
     * @var int
     */
    protected int $perPage = 20;

    /**
     * Total items count
     * @var int
     */
    protected int $totalCount = 0;

    /**
     * Previous page number, if not first
     * @var int|null
     */
    protected ?int $previous = null;

    /**
     * Next page number, if not last
     * @var int|null
     */
    protected ?int $next = null;

    /**
     * List of items for this page
     * @var array
     */
    protected array $items = [];

    /**
     * @var callable
     */
    protected array|string|object|null $jsonTransformer = null;


    /**
     * @param Builder $query
     * @param int $page
     * @param int $perPage
     */
    public function __construct(Builder $query, int $page, int $perPage = 20)
    {
        $this->page = $page < 1 ? 1 : $page;
        $this->perPage = $perPage < 1 ? 20 : $perPage;
        $this->totalCount = $query->count();
        $this->pageCount = ceil($this->totalCount / $this->perPage);
        $this->previous = $this->page > 1 ? $this->page - 1 : null;
        $this->next = $this->page < $this->pageCount ? $this->page + 1 : null;
        $this->items = $query->forPage($this->page, $this->perPage)->get();
    }


    /**
     * Set transformer to use when this collection is serialized as json
     *
     * @param callable $transformer
     *
     * @return self
     */
    public function setJsonTransformer(callable $transformer): self
    {
        $this->jsonTransformer = $transformer;

        return $this;
    }


    /**
     * Get current page number
     *
     * @return int
     */
    public function page(): int
    {
        return $this->page;
    }


    /**
     * Get the total page count
     *
     * @return int
     */
    public function pageCount(): int
    {
        return $this->pageCount;
    }


    /**
     * Get the number of items per page
     *
     * @return int
     */
    public function perPage(): int
    {
        return $this->perPage;
    }


    /**
     * Get the total items count found for all pages
     *
     * @return int
     */
    public function totalCount(): int
    {
        return $this->totalCount;
    }


    /**
     * Get the previous page number, or null if first
     *
     * @return int|null
     */
    public function previous(): ?int
    {
        return $this->previous;
    }


    /**
     * Get the next page number, or null if last
     *
     * @return int|null
     */
    public function next(): ?int
    {
        return $this->next;
    }


    /**
     * Get the items for this page
     *
     * @return array
     */
    public function items(): array
    {
        return $this->items;
    }


    /**
     * Check if a page is the current page
     *
     * @param int $page
     *
     * @return bool
     */
    public function isCurrent(int $page): bool
    {
        return $page == $this->page;
    }


    /**
     * @param mixed $offset
     * @param mixed $value
     * 
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        throw new Exception("The pagination object is read-only");
    }


    /**
     * @param mixed $offset
     * 
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }


    /**
     * @param mixed $offset
     * 
     * @return void
     */
    public function offsetUnset($offset): void
    {
        if (isset($this->items[$offset])) {
            unset($this->items[$offset]);
        }
    }


    /**
     * @param mixed $offset
     * 
     * @return mixed
     */
    public function offsetGet($offset): mixed
    {
        return isset($this->items[$offset])
            ? $this->items[$offset]
            : null;
    }


    /**
     * Get the current items count
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
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
     * Add a callback for each item in the list
     *
     * @param callable $transformer
     *
     * @return self
     */
    public function onEachItem(callable $transformer): self
    {
        foreach ($this->items as $index => $item) {
            $this->items[$index] = call_user_func_array($transformer, [$item]);
        }

        return $this;
    }


    /**
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return $this->jsonTransformer
            ? call_user_func_array($this->jsonTransformer, [$this])
            : [
                'page' => $this->page,
                'pages' => $this->pageCount,
                'perPage' => $this->perPage,
                'totalCount' => $this->totalCount,
                'previous' => $this->previous,
                'next' => $this->next,
                'items' => $this->items,
            ];
    }
}

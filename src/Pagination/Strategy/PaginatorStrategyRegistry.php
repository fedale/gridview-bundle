<?php

namespace Fedale\GridviewBundle\Pagination\Strategy;

use Fedale\GridviewBundle\Contract\PaginatorStrategyInterface;

/**
 * Collects paginator strategies by name. Fed by the
 * `fedale_gridview.paginator_strategy` tagged services, so a host app registers its
 * own strategy zero-config by implementing {@see PaginatorStrategyInterface}.
 *
 * Override-by-name (host > bundle): a host strategy reusing a built-in name (e.g.
 * 'numeric') replaces it. Built-ins are tagged with a higher priority so they come
 * first in the iterator and the later host entry wins ("last write wins").
 */
class PaginatorStrategyRegistry
{
    /** @var array<string, PaginatorStrategyInterface> */
    private array $strategies = [];

    /**
     * @param iterable<PaginatorStrategyInterface> $strategies
     */
    public function __construct(iterable $strategies = [])
    {
        foreach ($strategies as $strategy) {
            $this->strategies[$strategy->getName()] = $strategy;
        }
    }

    public function register(PaginatorStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->getName()] = $strategy;
    }

    public function has(string $name): bool
    {
        return isset($this->strategies[$name]);
    }

    public function get(string $name): PaginatorStrategyInterface
    {
        if (!isset($this->strategies[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown paginator strategy "%s". Known strategies: %s.',
                $name,
                $this->strategies === [] ? '(none)' : implode(', ', array_keys($this->strategies))
            ));
        }

        return $this->strategies[$name];
    }
}

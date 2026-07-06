<?php

namespace Fedale\GridviewBundle\Grid;

/**
 * Immutable configuration for row grouping: parent rows that expand to reveal
 * the records of a 1-N or N-M relation as child rows.
 *
 * The `mode` is the single switch between the two strategies that share
 * everything else (DTO, templates, child columns, client toggle):
 *   - 'eager': children are fetched and rendered up front, hidden client-side;
 *   - 'lazy':  parent rows carry no children until expanded, when they are
 *     fetched on demand (a later, additive strategy — same seams).
 */
final class GroupingConfig
{
    public const MODE_EAGER = 'eager';
    public const MODE_LAZY = 'lazy';

    /** Default template rendering a parent's children (the child sub-table body). */
    public const DEFAULT_TEMPLATE = '@FedaleGridview/gridview/sections/dataview/table/_group_table.html.twig';

    /**
     * @param array<int, mixed> $childColumns column specs (ColumnFactory format)
     */
    public function __construct(
        private bool $enabled,
        private string $mode,
        private string $relation,
        private array $childColumns,
        private ?string $label = null,
        private string $parentKey = 'id',
        private ?int $limit = null,
        private ?string $parentClass = null,
        private ?string $template = null,
    ) {
    }

    /**
     * Build from the `grouping` option array, tolerating a missing or partial
     * config. `$parentClass` is the entity backing the grid (the data provider
     * `model`), needed by the resolver to navigate the relation.
     *
     * @param array<string, mixed>|null $config
     */
    public static function fromArray(?array $config, ?string $parentClass = null): self
    {
        $config ??= [];

        $mode = $config['mode'] ?? self::MODE_EAGER;
        if (!\in_array($mode, [self::MODE_EAGER, self::MODE_LAZY], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Grouping mode must be "%s" or "%s", got "%s".',
                self::MODE_EAGER,
                self::MODE_LAZY,
                get_debug_type($mode) === 'string' ? $mode : get_debug_type($mode),
            ));
        }

        $enabled = (bool) ($config['enabled'] ?? false);
        $relation = (string) ($config['relation'] ?? '');
        if ($enabled && $relation === '') {
            throw new \InvalidArgumentException('Grouping requires a non-empty "relation".');
        }

        return new self(
            enabled: $enabled,
            mode: $mode,
            relation: $relation,
            childColumns: $config['columns'] ?? [],
            label: $config['label'] ?? null,
            parentKey: (string) ($config['childKey'] ?? $config['parentKey'] ?? 'id'),
            limit: isset($config['limit']) ? (int) $config['limit'] : null,
            parentClass: $parentClass,
            template: $config['template'] ?? null,
        );
    }

    public function withParentClass(?string $parentClass): self
    {
        $clone = clone $this;
        $clone->parentClass = $parentClass;

        return $clone;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isEager(): bool
    {
        return self::MODE_EAGER === $this->mode;
    }

    public function isLazy(): bool
    {
        return self::MODE_LAZY === $this->mode;
    }

    public function getRelation(): string
    {
        return $this->relation;
    }

    /**
     * @return array<int, mixed>
     */
    public function getChildColumns(): array
    {
        return $this->childColumns;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    /** The parent identifier field read from row data to key children by parent. */
    public function getParentKey(): string
    {
        return $this->parentKey;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Template rendering a parent's children (the sub-table body), receiving
     * `row`, `gridview` and `childColumns`. Override it per grid to customize the
     * child presentation; the surrounding detail row and toggle stay in place.
     */
    public function getTemplate(): string
    {
        return $this->template ?? self::DEFAULT_TEMPLATE;
    }

    public function getParentClass(): ?string
    {
        return $this->parentClass;
    }
}

<?php

namespace Fedale\GridviewBundle\Column\Type;

/**
 * Collects every {@see ColumnTypeInterface} (built-in or host-app) keyed by name.
 * Populated via a tagged_iterator, mirroring the exporter registry, so a host app
 * registers a custom type with zero config (a service implementing the interface
 * is auto-tagged). A custom type may override a built-in by reusing its name.
 */
class ColumnTypeRegistry
{
    /** @var array<string, ColumnTypeInterface> */
    private array $types = [];

    /** Legacy data-type aliases that map onto canonical type names. */
    private array $aliases = [
        'data'     => 'text',  // historical raw-ish alias of text
        'choice'   => 'select',
        'richtext' => 'richText',
    ];

    /** @param iterable<ColumnTypeInterface> $types */
    public function __construct(iterable $types = [])
    {
        foreach ($types as $type) {
            $this->types[$type->getName()] = $type;
        }
    }

    /**
     * Container factory: built-in types plus any host-app types (collected via a
     * tagged_iterator). A custom type reusing a built-in name overrides it.
     *
     * @param iterable<ColumnTypeInterface> $custom
     */
    public static function create(iterable $custom = []): self
    {
        $registry = self::withBuiltins();
        foreach ($custom as $type) {
            $registry->types[$type->getName()] = $type;
        }

        return $registry;
    }

    /**
     * Registry preloaded with every built-in type. Used as a fallback when the
     * factory is constructed outside the container (e.g. in unit tests).
     */
    public static function withBuiltins(): self
    {
        return new self([
            new TextType(),
            new UuidType(),
            new HtmlType(),
            new RichTextType(),
            new JsonType(),
            new LinkType(),
            new UrlType(),
            new EmailType(),
            new ImageType(),
            new NumberType(),
            new CurrencyType(),
            new PercentType(),
            new BooleanType(),
            new DateType(),
            new DatetimeType(),
            new SelectType(),
            new MultiSelectType(),
            new RatingType(),
            new BadgeType(),
            new ListType(),
            new RelationType(),
        ]);
    }

    public function has(string $name): bool
    {
        return isset($this->types[$this->aliases[$name] ?? $name]);
    }

    public function get(string $name): ColumnTypeInterface
    {
        $name = $this->aliases[$name] ?? $name;

        if (!isset($this->types[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown column type "%s". Known types: %s.',
                $name,
                implode(', ', $this->names())
            ));
        }

        return $this->types[$name];
    }

    /** @return string[] canonical names plus aliases */
    public function names(): array
    {
        return array_values(array_unique([
            ...array_keys($this->types),
            ...array_keys($this->aliases),
        ]));
    }
}

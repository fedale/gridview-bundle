<?php

namespace Fedale\GridviewBundle\Grid\Options;

/**
 * One node of the grid options schema: a declarative description of a value's
 * shape, read by {@see GridOptionsValidator} to report every mistake at once
 * and exported as JSON Schema for tools that author grid options (an MCP
 * server, an editor, a code generator).
 *
 * Deliberately small: the options tree only needs scalars, enums, lists,
 * structs (closed or open) and unions. It is not a general-purpose schema
 * library, and it never fills defaults — defaults stay where they are
 * (GridviewConfigRegistry, Gridview::$options).
 */
final class SchemaNode
{
    public const BOOL   = 'bool';
    public const INT    = 'int';
    public const STRING = 'string';
    public const LIST   = 'list';
    public const STRUCT = 'struct';
    public const ANY    = 'any';
    public const ANY_OF = 'anyOf';

    private bool $nullable = false;

    private ?string $description = null;

    /**
     * @param array<string, SchemaNode> $children   STRUCT: the known keys
     * @param SchemaNode|null           $additional STRUCT: the shape of any other key; null = closed (unknown keys are errors)
     * @param SchemaNode|null           $items      LIST: the shape of each item
     * @param list<string|int>|null     $enum       scalar: the only accepted values
     * @param list<SchemaNode>          $variants   ANY_OF: the accepted alternatives
     */
    private function __construct(
        public readonly string $type,
        public readonly array $children = [],
        public readonly ?self $additional = null,
        public readonly ?self $items = null,
        public readonly ?array $enum = null,
        public readonly array $variants = [],
    ) {
    }

    public static function bool(): self
    {
        return new self(self::BOOL);
    }

    public static function int(): self
    {
        return new self(self::INT);
    }

    public static function string(): self
    {
        return new self(self::STRING);
    }

    /**
     * @param list<string> $values
     */
    public static function enum(array $values): self
    {
        return new self(self::STRING, enum: $values);
    }

    public static function listOf(self $items): self
    {
        return new self(self::LIST, items: $items);
    }

    /**
     * A keyed array. Without $additional it is closed: a key outside $children
     * is reported, which is the whole point — an unknown option key is
     * otherwise merged and ignored without a trace.
     *
     * @param array<string, SchemaNode> $children
     */
    public static function struct(array $children, ?self $additional = null): self
    {
        return new self(self::STRUCT, children: $children, additional: $additional);
    }

    /**
     * A keyed array with free keys, every value of the given shape.
     */
    public static function mapOf(self $values): self
    {
        return new self(self::STRUCT, additional: $values);
    }

    /**
     * An open bag (any value). Used where the content belongs to another
     * component that validates it itself (a renderer's option bag, the CRUD
     * URLs, the grouping config).
     */
    public static function any(): self
    {
        return new self(self::ANY);
    }

    public static function anyOf(self ...$variants): self
    {
        return new self(self::ANY_OF, variants: array_values($variants));
    }

    public function nullable(): self
    {
        $clone = clone $this;
        $clone->nullable = true;

        return $clone;
    }

    public function describe(string $description): self
    {
        $clone = clone $this;
        $clone->description = $description;

        return $clone;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Short human label of the expected shape, for violation messages
     * ("bool", "string|null", "list of string", "one of: modal, page").
     */
    public function label(): string
    {
        $label = match ($this->type) {
            self::LIST   => 'list of ' . ($this->items?->label() ?? 'any'),
            self::STRUCT => 'array',
            self::ANY_OF => implode(' or ', array_map(static fn(self $v) => $v->label(), $this->variants)),
            default      => $this->enum !== null
                ? 'one of: ' . implode(', ', $this->enum)
                : $this->type,
        };

        return $this->nullable ? $label . '|null' : $label;
    }

    /**
     * JSON Schema (draft 2020-12 vocabulary) of this node. An unconstrained
     * node with no description comes back as an empty object rather than an
     * empty array, so json_encode() writes `{}` (the "anything" schema), not
     * `[]`, which is not a schema at all.
     *
     * @return array<string, mixed>|\stdClass
     */
    public function toJsonSchema(): array|\stdClass
    {
        $schema = match ($this->type) {
            self::BOOL   => ['type' => 'boolean'],
            self::INT    => ['type' => 'integer'],
            self::STRING => $this->enum !== null ? ['type' => 'string', 'enum' => $this->enum] : ['type' => 'string'],
            self::LIST   => ['type' => 'array', 'items' => $this->items?->toJsonSchema() ?? new \stdClass()],
            self::STRUCT => $this->structSchema(),
            self::ANY_OF => ['anyOf' => array_map(static fn(self $v) => $v->toJsonSchema(), $this->variants)],
            // ANY: no constraint, null included.
            default      => [],
        };

        if ($this->nullable && $schema !== []) {
            if (isset($schema['type'])) {
                $schema['type'] = [$schema['type'], 'null'];
                // An enum restricts the value set, so null has to be listed too.
                if (isset($schema['enum'])) {
                    $schema['enum'][] = null;
                }
            } else {
                $schema = ['anyOf' => [$schema, ['type' => 'null']]];
            }
        }

        if ($this->description !== null) {
            $schema['description'] = $this->description;
        }

        return $schema === [] ? new \stdClass() : $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function structSchema(): array
    {
        $schema = ['type' => 'object'];
        if ($this->children !== []) {
            $schema['properties'] = array_map(static fn(self $c) => $c->toJsonSchema(), $this->children);
        }
        $schema['additionalProperties'] = $this->additional === null
            ? false
            : ($this->additional->type === self::ANY ? true : $this->additional->toJsonSchema());

        return $schema;
    }
}

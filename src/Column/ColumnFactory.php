<?php

namespace Fedale\GridviewBundle\Column;

use Fedale\GridviewBundle\Column\Config\ColumnConfigInterface;
use Fedale\GridviewBundle\Column\Type\ColumnTypeRegistry;
use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Form\Control\ControlResolver;
use Fedale\GridviewBundle\Grid\Gridview;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @phpstan-type ColumnSpec array{
 *     attribute?: string,
 *     type?: string|\Fedale\GridviewBundle\Column\Type\ColumnType,
 *     label?: string|bool,
 *     value?: callable|string,
 *     valueGetter?: callable,
 *     formatter?: callable,
 *     renderer?: callable,
 *     format?: array<string, mixed>,
 *     filter?: bool|string|\Fedale\GridviewBundle\Filter\FilterType|array<string, mixed>,
 *     control?: bool|string|\Fedale\GridviewBundle\Form\Control\ControlType|array<string, mixed>,
 *     sortable?: bool,
 *     visible?: bool|callable,
 *     active?: bool|callable,
 *     sources?: array<int, string|array<string, mixed>|ColumnConfigInterface>,
 *     views?: string|string[],
 *     hideInViews?: string|string[],
 *     twigFilter?: string,
 *     filterBar?: bool,
 *     editable?: bool,
 *     ...
 * }
 */
class ColumnFactory
{
    private ControlResolver $controlResolver;
    private ColumnTypeRegistry $typeRegistry;

    public function __construct(
        private ?AuthorizationCheckerInterface $authChecker = null,
        ?ControlResolver $controlResolver = null,
        ?ColumnTypeRegistry $typeRegistry = null,
    ) {
        $this->controlResolver = $controlResolver ?? new ControlResolver();
        $this->typeRegistry    = $typeRegistry ?? ColumnTypeRegistry::withBuiltins();
    }

    /**
     * Structural columns: selected by the root `type` and backed by a dedicated
     * column class. Custom types may be added through register().
     */
    private array $registry = [
        'action'   => ActionColumn::class,
        'checkbox' => CheckboxColumn::class,
        'serial'   => SerialColumn::class,
    ];

    /**
     * Expands the composite specs in an authored column list. A virtual column
     * carries the fields it is built from in `sources`; each becomes a column of
     * its own, spliced in right after it, so the grid shows one column and the
     * CRUD form gets the real fields back.
     *
     * Runs before anything is instantiated, so the rest of the pipeline only ever
     * sees ordinary columns. Keys are preserved (a spec without an `attribute`
     * falls back to its key for a name) and the spliced sources get their own
     * string keys, which cannot collide with the authored ones.
     *
     * @param array<int|string, ColumnConfigInterface|array<string, mixed>|string> $specs
     *
     * @return array<int|string, ColumnConfigInterface|array<string, mixed>|string>
     */
    public static function expand(array $specs): array
    {
        $expanded = [];
        foreach ($specs as $key => $spec) {
            $array = $spec instanceof ColumnConfigInterface ? $spec->toArray() : $spec;
            if (!\is_array($array) || !isset($array['sources']) || !\is_array($array['sources'])) {
                $expanded[$key] = $spec;
                continue;
            }

            $sources = $array['sources'];
            unset($array['sources']);
            $expanded[$key] = $array;

            foreach ($sources as $source) {
                $sourceKey = \is_array($source) && isset($source['attribute'])
                    ? $key . ':' . $source['attribute']
                    : $key . ':' . \count($expanded);
                $expanded[$sourceKey] = $source;
            }
        }

        return $expanded;
    }

    /** Register a custom column type. Call from a Symfony CompilerPass or bundle boot. */
    public function register(string $type, string $columnClass): void
    {
        if (!is_a($columnClass, ColumnInterface::class, true)) {
            throw new \InvalidArgumentException(
                sprintf('Column class "%s" must implement %s.', $columnClass, ColumnInterface::class)
            );
        }

        $this->registry[$type] = $columnClass;
    }

    public function create(ColumnConfigInterface|array|string $spec, Gridview $gridview, int|string $key): ColumnInterface
    {
        if ($spec instanceof ColumnConfigInterface) {
            $spec = $spec->toArray();
        }

        if (\is_string($spec)) {
            return $this->createFromString($spec, $gridview);
        }

        return $this->createFromArray($spec, $gridview, $key);
    }

    private function createFromString(string $text, Gridview $gridview): ColumnInterface
    {
        if (!preg_match('/^([^:]+)(:(\w*))?(:(.*))?$/', $text, $m)) {
            throw new \InvalidArgumentException(
                'Column spec must be "attribute", "attribute:filter" or "attribute:filter:label".'
            );
        }

        $column = new DataColumn($gridview, $m[1], $m[3] ?? null, $m[5] ?? $m[1]);
        $column->setColumnType($this->typeRegistry->get('text'));

        return $column;
    }

    private function createFromArray(array $spec, Gridview $gridview, int|string $key): ColumnInterface
    {
        $type      = $spec['type'] ?? 'text';
        $type      = $type instanceof \BackedEnum ? $type->value : $type;
        $attribute = $spec['attribute'] ?? 'column_' . $key;
        $value     = $spec['value'] ?? null;

        // `sources` is an authoring-time key consumed by expand(); a spec that
        // reaches here still carrying one was built outside the authoring entry
        // points, and the column renders fine without it (its value closure is
        // part of the spec) — it just contributes no columns of its own.
        unset($spec['attribute'], $spec['value'], $spec['type'], $spec['sources']);

        // Structural / custom columns keep their dedicated class.
        if (isset($this->registry[$type])) {
            $class  = $this->registry[$type];
            $column = $type === 'action'
                ? new $class($gridview, $attribute, null, $spec['label'] ?? null, [], $this->authChecker)
                : new $class($gridview, null, $spec['label'] ?? $attribute, []);
        } elseif ($this->typeRegistry->has($type)) {
            // Data column carrying a semantic data type from the type registry.
            $columnType = $this->typeRegistry->get($type);
            $column = new DataColumn($gridview, $attribute, null, $spec['label'] ?? $attribute, []);
            $column->value = $value;
            $column->setDataType($type);
            $column->setColumnType($columnType);

            // The per-column filter inherits the type's default filter unless it
            // names its own type, which always wins.
            if (\array_key_exists('filter', $spec)) {
                $spec['filter'] = $this->normalizeFilter($spec['filter'], $columnType->inferFilterType());
            }

            // The per-column control (write-side field) inherits the type's
            // default control unless it names its own type, mirroring the filter.
            if (\array_key_exists('control', $spec)) {
                $control = $spec['control'];
                if ($control instanceof \BackedEnum) {
                    $control = $control->value;
                } elseif (\is_array($control) && ($control['type'] ?? null) instanceof \BackedEnum) {
                    $control['type'] = $control['type']->value;
                }

                $spec['control'] = $this->controlResolver->resolve(
                    $control,
                    $columnType->inferControlType() ?? 'text'
                );
            }
        } else {
            throw new \InvalidArgumentException(sprintf('Unknown column type "%s".', $type));
        }

        foreach ($spec as $property => $val) {
            $setter = 'set' . ucfirst($property);
            if (!method_exists($column, $setter)) {
                throw new \InvalidArgumentException(
                    sprintf('Column of type "%s" has no setter for property "%s".', $type, $property)
                );
            }
            $column->$setter($val);
        }

        return $column;
    }

    /**
     * Normalizes a column's `filter` spec into an array with a resolved `type`.
     * A `filter.type` set explicitly always wins; otherwise the type's default
     * filter is inherited (falling back to "text" when the type is not filterable).
     */
    private function normalizeFilter(mixed $filter, ?string $inheritedType): array
    {
        $inherited = $inheritedType ?? 'text';

        if ($filter instanceof \BackedEnum) {
            $filter = $filter->value;
        }

        if (\is_array($filter)) {
            if (($filter['type'] ?? null) instanceof \BackedEnum) {
                $filter['type'] = $filter['type']->value;
            }
            $filter['type'] ??= $inherited;

            return $filter;
        }

        if (\is_string($filter)) {
            return ['type' => $filter];
        }

        // `filter => true` (or any other truthy scalar) inherits the root type.
        return ['type' => $inherited];
    }
}

<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

/**
 * One column to read, several to write.
 *
 * A grid often wants a field that does not exist: a full name built from a first
 * and a last name, an address joined from street, city and zip. Showing it is
 * easy enough — a closure — but the CRUD form then needs the real fields back,
 * which means declaring the same thing twice and keeping the two halves in step.
 *
 * A virtual column declares both at once. It renders the joined value in the grid
 * and the detail view, and {@see \Fedale\GridviewBundle\Column\ColumnFactory::expand()}
 * turns each of its `sources` into a column of its own, hidden from those two
 * contexts and carrying the control that edits the underlying field:
 *
 *     VirtualColumn::new('fullName')->label('Name')->from(['firstName', 'lastName']),
 *
 * Give a source a builder of its own when it needs more than a default text
 * control — a label, a type, validation:
 *
 *     VirtualColumn::new('fullName')->label('Name')->from([
 *         TextColumn::new('firstName')->label('First name')->required(),
 *         TextColumn::new('lastName')->label('Last name')->required(),
 *     ]),
 *
 * Sorting and filtering stay the grid's business: the joined value exists only at
 * render time, so point a `sort.map` entry at the real fields to sort by it (the
 * map takes several fields per key) and use `search.fields` or the global search
 * to search across them.
 */
class VirtualColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Text;
    }

    /**
     * The fields this column is built from. Each source may be an attribute name,
     * another column builder, or a raw column spec.
     *
     * Unless the source says otherwise it is given a default control (so it shows
     * up in the create/update form) and is hidden from the grid and the detail
     * view (where this column stands for it). Anything the source declares
     * explicitly wins — pass `active` yourself to put a source back on screen.
     *
     * @param list<string|ColumnConfigInterface|array<string, mixed>> $sources
     * @param string                                                  $separator placed between the rendered parts
     */
    public function from(array $sources, string $separator = ' '): static
    {
        if ($sources === []) {
            throw new \InvalidArgumentException(sprintf(
                'Virtual column "%s" needs at least one source field.',
                $this->spec['attribute'] ?? 'unknown',
            ));
        }

        $specs = [];
        $paths = [];
        foreach ($sources as $source) {
            $spec = $this->normalizeSource($source);
            $specs[] = $spec;
            $paths[] = $spec['attribute'];
        }

        $this->spec['sources'] = $specs;

        // Both defaults step aside for an explicit declaration, so the order of
        // the fluent calls never decides the outcome.
        if (!\array_key_exists('valueGetter', $this->spec) && !\array_key_exists('value', $this->spec)) {
            $this->spec['valueGetter'] = self::joiner($paths, $separator);
        }
        if (!\array_key_exists('active', $this->spec)) {
            $this->hideOnForm();
        }

        return $this;
    }

    /**
     * Reads each source from the row and joins what is there. Empty parts are
     * dropped rather than padded, so a missing middle name leaves no double
     * separator behind.
     *
     * @param list<string> $paths
     */
    private static function joiner(array $paths, string $separator): \Closure
    {
        return static function (array $data) use ($paths, $separator): string {
            $parts = [];
            foreach ($paths as $path) {
                // Dot notation reaches into a normalized relation, the same way a
                // plain column's `attribute` does.
                $value = $data;
                foreach (explode('.', $path) as $segment) {
                    if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                        $value = null;
                        break;
                    }
                    $value = $value[$segment];
                }

                if ($value !== null && $value !== '' && !\is_array($value)) {
                    $parts[] = (string) $value;
                }
            }

            return implode($separator, $parts);
        };
    }

    /**
     * The union is enforced natively: anything else is a programming error, and
     * PHP's own TypeError names the offending type better than a hand-rolled
     * check could.
     *
     * @param string|ColumnConfigInterface|array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function normalizeSource(string|ColumnConfigInterface|array $source): array
    {
        $spec = match (true) {
            \is_string($source)                    => ['attribute' => $source],
            $source instanceof ColumnConfigInterface => $source->toArray(),
            default                                => $source,
        };

        if (!isset($spec['attribute']) || !\is_string($spec['attribute']) || $spec['attribute'] === '') {
            throw new \InvalidArgumentException(sprintf(
                'Every source of the virtual column "%s" needs an `attribute`: it is the field the form writes to.',
                $this->spec['attribute'] ?? 'unknown',
            ));
        }

        // A source with no control would vanish from the form, which is the one
        // thing a virtual column exists to avoid.
        $spec['control'] ??= true;

        // Hidden where this column stands for the source, unless the source says
        // otherwise. A non-array `active` (false, a closure) is a decision of its
        // own and is left alone.
        if (!\array_key_exists('active', $spec)) {
            $spec['active'] = ['inIndex' => false, 'inShow' => false];
        } elseif (\is_array($spec['active'])) {
            $spec['active'] = [...['inIndex' => false, 'inShow' => false], ...$spec['active']];
        }

        return $spec;
    }
}

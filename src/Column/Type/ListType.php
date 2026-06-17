<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * List / array — a collection rendered as a <ul> (or inline when `separator`
 * is set). Options: `separator` (inline join), `tag` ('ul'|'ol', default 'ul').
 * A string value is split on commas.
 */
class ListType extends AbstractColumnType
{
    public function getName(): string
    {
        return 'list';
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (\is_array($value)) {
            return $value;
        }

        return \array_map('trim', \explode(',', (string) $value));
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        $items = (array) $value;
        if ($items === []) {
            return '';
        }

        if (isset($options['separator'])) {
            return \implode($options['separator'], \array_map(fn ($i) => (string) $i, $items));
        }

        $tag = $options['tag'] ?? 'ul';
        if (!\in_array($tag, ['ul', 'ol'], true)) {
            $tag = 'ul';
        }
        $html = '<' . $tag . ' class="gv-list">';
        foreach ($items as $item) {
            $html .= '<li>' . $this->esc($item) . '</li>';
        }
        $html .= '</' . $tag . '>';

        return $this->markup($html);
    }

    public function inferFilterType(): ?string
    {
        return null;
    }
}

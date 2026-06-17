<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/** Structured JSON — pretty-printed inside a <pre> (escaped). */
class JsonType extends TextType
{
    public function getName(): string
    {
        return 'json';
    }

    public function getParent(): ?string
    {
        return 'text';
    }

    public function format(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (\is_string($value)) {
            $decoded = \json_decode($value, true);
            $value = \json_last_error() === \JSON_ERROR_NONE ? $decoded : $value;
        }

        return \json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->markup('<pre class="gv-json">' . $this->esc($value) . '</pre>');
    }

    public function inferFilterType(): ?string
    {
        return null;
    }
}

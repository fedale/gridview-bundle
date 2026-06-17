<?php

namespace Fedale\GridviewBundle\Column;

use Fedale\GridviewBundle\Grid\Gridview;

class BooleanColumn extends AbstractColumn
{
    public function __construct(
        private Gridview $gridview,
        private string $attribute,
        protected ?string $twigFilter = null,
        protected ?string $label = null,
        protected ?array $options = []
    ) {
        $this->sortable   = false;
        $this->filterable = false;
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function render($row, $_index): string
    {
        $value = $row->data[$this->attribute] ?? null;

        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true'  => '✓',
            $value === false, $value === 0, $value === '0', $value === 'false' => '✗',
            default => '',
        };
    }
}

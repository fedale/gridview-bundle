<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

/**
 * Structural row-actions column. Left without `buttons`, the CRUD controller
 * auto-wires the default show/edit/clone/delete buttons for the routes that
 * exist (see AbstractCrudGridController::defaultActionButtons()).
 */
class ActionColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Action;
    }

    /**
     * @param array<string, mixed> $buttons token => ActionButtonInterface|callable|string|array
     */
    public function buttons(array $buttons): static
    {
        $this->spec['buttons'] = $buttons;

        return $this;
    }

    /** Token layout string, e.g. '{show} {edit} {delete}'. */
    public function layout(string $layout): static
    {
        $this->spec['layout'] = $layout;

        return $this;
    }
}

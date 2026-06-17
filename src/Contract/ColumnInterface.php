<?php

namespace Fedale\GridviewBundle\Contract;

use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Grid\Gridview;
use Symfony\Component\Form\FormBuilderInterface;

interface ColumnInterface
{
    public function isVisible(): bool;

    public function isFilterable(): bool;

    public function isSortable(): bool;

    public function isToggleable(): bool;

    public function setGridview(Gridview $gridview): void;

    public function getAttribute(): ?string;

    public function getLabel(): ?string;

    public function getTwigFilter(): ?string;

    /**
     * Normalized write-side control spec, or null when the column is not editable.
     *
     * @return array{type: string, required: bool, options: array}|null
     */
    public function getControl(): ?array;

    /** Adds this column's editable field to the given form builder (no-op when not editable). */
    public function buildControl(FormBuilderInterface $form, ControlTypeRegistry $registry): void;

    /** Whether/how this column appears in the delete-confirm recap (bool|array). */
    public function getShowInDeleteConfirm(): bool|array;

    /** Whether this column is editable in the bulk batch-update dialog. */
    public function isBatchUpdate(): bool;

    /** Whether this column is included in exports. */
    public function isExportable(): bool;

    /** Whether this column supports inline cell editing (truthy `editable` + a control). */
    public function isEditable(): bool;

    /** The inline-edit trigger event: 'click' or 'dblclick' (default). */
    public function getEditableTrigger(): string;

    /** Render a data cell for the given row model. */
    public function render(mixed $model, int $index): mixed;

    /** Render the column header cell (may include sort link). */
    public function renderHeader(mixed $label): string;
}

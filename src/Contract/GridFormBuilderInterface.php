<?php

namespace Fedale\GridviewBundle\Contract;

use Symfony\Component\Form\FormInterface;

interface GridFormBuilderInterface
{
    /**
     * Builds a Symfony form bound to $dataClass from the columns that declare a
     * `control`. $data is the entity instance (edit/clone) or null (add).
     *
     * @param iterable<ColumnInterface> $columns
     */
    public function build(string $dataClass, iterable $columns, ?object $data = null, array $options = []): FormInterface;

    /**
     * Builds the bulk batch-update form from columns flagged `batchUpdate`
     * (not bound to an entity).
     *
     * @param iterable<ColumnInterface> $columns
     */
    public function buildBatchForm(iterable $columns, array $options = []): FormInterface;

    /**
     * The attribute names of the columns that contribute a control, in order.
     *
     * @param iterable<ColumnInterface> $columns
     * @return string[]
     */
    public function controlAttributes(iterable $columns): array;
}

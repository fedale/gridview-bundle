<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Component\Form\DataMapperInterface;

/**
 * Maps a nullable colour (NullableColorType) onto its two children and back: null checks "No
 * color" and leaves the colour input at black, the browser's own empty value.
 *
 * @internal
 */
final class NullableColorMapper implements DataMapperInterface
{
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $children = iterator_to_array($forms);
        $color = \is_string($viewData) && $viewData !== '' ? strtolower($viewData) : null;
        $children['color']->setData($color ?? '#000000');
        $children['none']->setData($color === null);
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        $children = iterator_to_array($forms);
        $color = $children['color']->getData();
        $viewData = $children['none']->getData() === true || !\is_string($color) || $color === '' ? null : strtolower($color);
    }
}

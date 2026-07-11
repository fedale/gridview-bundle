<?php

namespace Fedale\GridviewBundle\Column\Type;

/**
 * Write-only value — plain passthrough rendering identical to {@see TextType},
 * whose only real purpose is auto-wiring the matching `hidden` control (a
 * Symfony `HiddenType`). Combine with `visible: false` to keep it out of the
 * grid entirely while still round-tripping through the create/update form.
 * Mirrors EasyAdmin's `HiddenField`.
 */
class HiddenType extends TextType
{
    public function getName(): string
    {
        return 'hidden';
    }

    public function getParent(): ?string
    {
        return 'text';
    }

    /** Not meaningfully filterable — the value is never shown to filter against. */
    public function inferFilterType(): ?string
    {
        return null;
    }

    public function inferControlType(): ?string
    {
        return 'hidden';
    }
}

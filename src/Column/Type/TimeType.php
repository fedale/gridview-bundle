<?php

namespace Fedale\GridviewBundle\Column\Type;

/** Time only (no date) — a DateType defaulting to a time-only pattern/style. */
class TimeType extends DateType
{
    public function getName(): string
    {
        return 'time';
    }

    public function getParent(): ?string
    {
        return 'date';
    }

    protected function intlStyles(): array
    {
        return [\IntlDateFormatter::NONE, \IntlDateFormatter::SHORT];
    }

    protected function noIntlPattern(): string
    {
        return 'H:i';
    }

    public function inferControlType(): ?string
    {
        return 'time';
    }
}

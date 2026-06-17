<?php

namespace Fedale\GridviewBundle\Column\Type;

/** Date with time — a DateType defaulting to a date+time pattern. */
class DatetimeType extends DateType
{
    public function getName(): string
    {
        return 'datetime';
    }

    public function getParent(): ?string
    {
        return 'date';
    }

    public function getDefaultOptions(): array
    {
        return ['pattern' => 'd/m/Y H:i'];
    }
}

<?php

namespace Fedale\GridviewBundle\Contract;

interface ActionButtonInterface
{
    public function render(mixed $model, int $index): string;

    public function isVisible(mixed $model, int $index): bool;

    /** @return string[] */
    public function getRoles(): array;
}

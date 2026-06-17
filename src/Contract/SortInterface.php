<?php

namespace Fedale\GridviewBundle\Contract;

use Fedale\GridviewBundle\Grid\Gridview;

interface SortInterface
{
    public function setAttributes(array $attributes): static;

    public function hasAttribute(?string $attribute): bool;

    public function fetchOrders(): array;

    public function getSortParam(): string;

    public function createLink(string $attribute, Gridview $gridview, array $options = []): string;
}

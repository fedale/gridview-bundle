<?php

namespace Fedale\GridviewBundle\Contract;

interface PaginationInterface
{
    public function setAttributes(array $attributes): static;

    public function setTotalCount(int|float $totalCount): static;

    public function getPageSize(): ?int;

    public function getOffset(): int;

    public function getPageCount(): int;

    public function getCurrentPage(): int;

    public function getPageParamName(): string;
}

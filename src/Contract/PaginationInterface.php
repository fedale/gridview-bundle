<?php

namespace Fedale\GridviewBundle\Contract;

interface PaginationInterface
{
    public function setAttributes(array $attributes): static;

    public function setTotalCount(int|float $totalCount): static;

    public function getPageSize(): ?int;

    /**
     * The page size used when the request asks for none, or for one outside
     * the selectable options: the configured `defaultPageSize`, 20 unless the
     * host app overrides it. Data providers fall back to it when getPageSize()
     * returns null.
     */
    public function getDefaultPageSize(): int;

    public function getOffset(): int;

    public function getPageCount(): int;

    public function getCurrentPage(): int;

    public function getPageParamName(): string;

    public function getPageSizeParam();

    /** @return int[] */
    public function getPageSizeOptions(): array;
}

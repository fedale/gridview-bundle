<?php

namespace Fedale\GridviewBundle\Profiler;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes every grid rendered during the request to the WebProfiler toolbar and
 * panel. Reads the per-request {@see GridviewProfileRegistry} and stores plain,
 * serializable arrays so the profiler can persist them.
 */
final class GridviewDataCollector extends AbstractDataCollector
{
    public function __construct(private readonly GridviewProfileRegistry $registry) {}

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data['grids'] = array_map(
            static fn(GridviewProfile $profile): array => $profile->toArray(),
            $this->registry->all(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getGrids(): array
    {
        return $this->data['grids'] ?? [];
    }

    public function getGridCount(): int
    {
        return \count($this->getGrids());
    }

    public function getTotalRows(): int
    {
        return array_sum(array_column($this->getGrids(), 'rowsOnPage'));
    }

    public function reset(): void
    {
        $this->data = [];
    }

    public function getName(): string
    {
        return 'fedale_gridview';
    }

    public static function getTemplate(): ?string
    {
        return '@FedaleGridview/collector/gridview.html.twig';
    }
}

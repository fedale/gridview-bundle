<?php

namespace Fedale\GridviewBundle\Export;

/**
 * Registry of available export formats. Built-in (CSV) plus any host-app service
 * implementing {@see ExporterInterface} (collected via the tagged iterator /
 * autoconfiguration).
 */
class GridExporterRegistry
{
    /** @var array<string, ExporterInterface> */
    private array $exporters = [];

    /**
     * @param iterable<ExporterInterface> $exporters
     */
    public function __construct(iterable $exporters = [])
    {
        foreach ($exporters as $exporter) {
            $this->register($exporter);
        }
    }

    public function register(ExporterInterface $exporter): void
    {
        $this->exporters[$exporter->getKey()] = $exporter;
    }

    public function has(string $key): bool
    {
        return isset($this->exporters[$key]);
    }

    public function get(string $key): ExporterInterface
    {
        if (!isset($this->exporters[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown export format "%s". Available: %s.',
                $key,
                implode(', ', array_keys($this->exporters))
            ));
        }

        return $this->exporters[$key];
    }

    /** @return array<string, ExporterInterface> */
    public function all(): array
    {
        return $this->exporters;
    }
}

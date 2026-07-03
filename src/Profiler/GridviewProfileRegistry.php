<?php

namespace Fedale\GridviewBundle\Profiler;

/**
 * Per-request collector of grid render snapshots. The active {@see Gridview}
 * records into it during renderGrid(); the WebProfiler data collector reads it
 * once at the end of the request.
 *
 * A no-op when disabled (production): the grid checks {@see isEnabled()} before
 * building a profile, so no DQL or snapshot is produced when the profiler is off.
 */
final class GridviewProfileRegistry
{
    /** @var list<GridviewProfile> */
    private array $profiles = [];

    public function __construct(private readonly bool $enabled = false)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function record(GridviewProfile $profile): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->profiles[] = $profile;
    }

    /**
     * @return list<GridviewProfile>
     */
    public function all(): array
    {
        return $this->profiles;
    }

    public function reset(): void
    {
        $this->profiles = [];
    }
}

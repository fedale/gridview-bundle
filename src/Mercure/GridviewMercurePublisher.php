<?php

namespace Fedale\GridviewBundle\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes a lightweight "grid changed" signal to a per-grid Mercure topic.
 *
 * The hub is optional: when symfony/mercure is not installed/configured the
 * service is built with a null hub and every {@see publish()} is a no-op, so the
 * bundle keeps working without a real-time backend.
 *
 * The payload is intentionally minimal (grid id + action, no record data): every
 * observer of the grid reacts by refetching the grid through its own request,
 * which re-applies that user's filters and authorization. This avoids leaking
 * rows a subscriber should not see and keeps the displayed rows consistent with
 * the active filter.
 */
class GridviewMercurePublisher
{
    public function __construct(private ?HubInterface $hub = null)
    {
    }

    /** The topic a grid publishes to and subscribers listen on. */
    public function topicFor(string $gridId, string $prefix = 'gridview/'): string
    {
        return $prefix . $gridId;
    }

    /**
     * Broadcasts a private "something changed on this grid" signal.
     *
     * @param string $action one of create|update|delete (free-form, informational)
     */
    public function publish(string $gridId, string $action, string $prefix = 'gridview/'): void
    {
        if ($this->hub === null) {
            return;
        }

        $update = new Update(
            $this->topicFor($gridId, $prefix),
            json_encode(['gridId' => $gridId, 'action' => $action], \JSON_THROW_ON_ERROR),
            private: true,
        );

        $this->hub->publish($update);
    }
}

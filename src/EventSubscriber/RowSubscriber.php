<?php

namespace Fedale\GridviewBundle\EventSubscriber;

use Fedale\GridviewBundle\Event\RowEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RowSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RowEvent::BEFORE_ROW => 'onBeforeRow',
            RowEvent::AFTER_ROW  => 'onAfterRow',
        ];
    }

    public function onBeforeRow(RowEvent $event): void
    {
        if (($event->row->data['id'] ?? null) !== null && $event->row->data['id'] % 2 === 0) {
            $event->row->setAttr('class', 'randomClass');
        }
    }

    public function onAfterRow(RowEvent $event): void
    {
        // Example: override a field after row is added to the collection.
        // $event->row->data['email'] = 'overridden@example.com';
    }
}

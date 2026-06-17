<?php

namespace Fedale\GridviewBundle\Event;

use Fedale\GridviewBundle\Row\Row;
use Symfony\Contracts\EventDispatcher\Event;

class RowEvent extends Event
{
    public const BEFORE_ROW = 'row.before_row';
    public const AFTER_ROW = 'row.after_row';

    public Row $row;
}
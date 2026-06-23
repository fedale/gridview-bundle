<?php

namespace Fedale\GridviewBundle\Row;

class Row
{
    public array $data = [];

    public array $attr = [];

    public string $prefixKey = 'row_';

    public function __construct(int $key, int $total, int $offset = 0)
    {
        $i = $key + 1;
        // Offset makes the row id globally unique across pages. Per-page ids would
        // collide between pages (row_1, row_2, …), and Turbo's stream `append`
        // de-duplicates target children by id — so appended rows with colliding
        // ids would REPLACE the existing ones instead of stacking (breaks infinite
        // scroll). first/middle/last and even/odd stay per-page (unchanged).
        $this->setAttr('id', $this->prefixKey . (string) ($offset + $i));

        if ($key == 0) {
            $this->setAttr('class', 'first');
        } else if ($key == $total) {
            $this->setAttr('class', 'last');
        } else {
            $this->setAttr('class', 'middle');
        }

        if ($i % 2 == 0) {
            $this->setAttr('class', 'even');
        } else {
            $this->setAttr('class', 'odd');
        }
    }

    public function setAttr(string $key, string $value, $replace = false)
    {
        if (!isset($this->attr[$key])) {
            $this->attr[$key] = $value;
        } else {
            if ($replace) {
                $this->attr[$key] = $value;
            } else {
                $this->attr[$key] .= ' ' . $value;
            }
        }
    }
}

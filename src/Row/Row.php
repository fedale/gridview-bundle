<?php

namespace Fedale\GridviewBundle\Row;

class Row
{
    public array $data = [];

    public array $attr = [];

    public string $prefixKey = 'row_';

    public function __construct(int $key, int $total)
    {
        $i = $key + 1;
        $this->setAttr('id', $this->prefixKey . (string) $i);

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

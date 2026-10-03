<?php

namespace Fedale\GridviewBundle\Row;

class Row
{
    public array $data = [];

    /**
     * The record's identifier as a URL-safe token, set by a data provider that
     * knows the key (see {@see \Fedale\GridviewBundle\Doctrine\EntityIdentifier}).
     * Null for a provider that does not — a JSON API's rows, say.
     */
    public ?string $identifierToken = null;

    public array $attr = [];

    public string $prefixKey = 'row_';

    /**
     * Whether this row is a grouping parent (expands to reveal children). Only
     * set on grouped grids; a plain grid leaves it false.
     */
    public bool $isParent = false;

    /**
     * Child rows of a grouping parent, one per related record. Populated up
     * front in eager mode and left empty in lazy mode (filled on expand).
     *
     * @var array<int, Row>
     */
    public array $children = [];

    /**
     * Child count for a grouping parent in lazy mode, resolved without fetching
     * any child (gates the expand toggle and feeds the count badge). Left at 0
     * when the resolver can't count, or in eager mode where `children` is the
     * source of truth.
     */
    public int $childCount = 0;

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

    /**
     * What the links, the selection checkboxes and the inline editor address this
     * record by: the provider's token when there is one, else a plain `id` in the
     * row data, so a provider with no notion of a key still works.
     */
    public function getIdentifier(): ?string
    {
        if ($this->identifierToken !== null) {
            return $this->identifierToken;
        }

        $id = $this->data['id'] ?? null;

        return \is_scalar($id) ? (string) $id : null;
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

<?php

namespace Fedale\GridviewBundle\Grid\Options;

/**
 * One problem found in a grid's options: where (a dotted path inside the
 * options array, e.g. `display.renderer.default`) and what.
 */
final class OptionViolation
{
    public function __construct(
        public readonly string $path,
        public readonly string $message,
    ) {
    }

    public function __toString(): string
    {
        return ($this->path === '' ? '(root)' : $this->path) . ': ' . $this->message;
    }

    /**
     * @return array{path: string, message: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'message' => $this->message];
    }
}

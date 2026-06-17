<?php

namespace Fedale\GridviewBundle\Column;

use Fedale\GridviewBundle\Contract\ActionButtonInterface;

class ActionButton implements ActionButtonInterface
{
    /**
     * @param string|\Closure(mixed, int): string $content  HTML string or callable that returns HTML
     * @param string[]                            $roles     Symfony roles; empty = always shown
     * @param bool|\Closure(mixed, int): bool     $visible
     */
    public function __construct(
        private string|\Closure $content,
        private array $roles = [],
        private bool|\Closure $visible = true,
    ) {}

    public function render(mixed $model, int $index): string
    {
        return $this->content instanceof \Closure
            ? ($this->content)($model, $index)
            : $this->content;
    }

    public function isVisible(mixed $model, int $index): bool
    {
        return $this->visible instanceof \Closure
            ? (bool) ($this->visible)($model, $index)
            : $this->visible;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }
}

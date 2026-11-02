<?php

namespace Fedale\GridviewBundle\Exception;

use Fedale\GridviewBundle\Grid\Options\OptionViolation;

/**
 * Thrown in strict mode when a grid's options contain keys or values the grid
 * would otherwise ignore without a trace. Carries every violation, not just
 * the first, so all of them can be fixed in one pass.
 */
class InvalidGridOptionsException extends \InvalidArgumentException
{
    /**
     * @param list<OptionViolation> $violations
     */
    public function __construct(
        private readonly array $violations,
        private readonly ?string $gridId = null,
    ) {
        parent::__construct(sprintf(
            "Gridview \"%s\" has %d invalid option(s):\n%s",
            $gridId ?? '?',
            count($violations),
            implode("\n", array_map(static fn(OptionViolation $v) => ' - ' . $v, $violations)),
        ));
    }

    /**
     * @return list<OptionViolation>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    public function getGridId(): ?string
    {
        return $this->gridId;
    }
}

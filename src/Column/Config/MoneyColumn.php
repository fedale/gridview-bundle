<?php

namespace Fedale\GridviewBundle\Column\Config;

use Fedale\GridviewBundle\Column\Type\ColumnType;

class MoneyColumn extends AbstractColumnConfig
{
    protected static function columnType(): ColumnType
    {
        return ColumnType::Money;
    }

    /** ISO 4217 currency code, e.g. 'EUR' (default) or 'USD'. */
    public function currency(string $currency): static
    {
        return $this->format(['currency' => $currency]);
    }

    public function decimals(int $decimals): static
    {
        return $this->format(['decimals' => $decimals]);
    }
}

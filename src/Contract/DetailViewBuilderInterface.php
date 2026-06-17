<?php

namespace Fedale\GridviewBundle\Contract;

/**
 * Mirror of {@see GridviewBuilderInterface} for the single-record DetailView.
 * Where the grid is anchored on a data provider (a list), the detail is
 * anchored on a single model.
 */
interface DetailViewBuilderInterface
{
    public function setModel(object $model): static;
}

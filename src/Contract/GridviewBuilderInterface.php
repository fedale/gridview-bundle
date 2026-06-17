<?php

namespace Fedale\GridviewBundle\Contract;

interface GridviewBuilderInterface
{
    public function setDataProvider(array $dataProviderOptions): GridviewBuilderInterface;
}

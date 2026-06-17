<?php

namespace Fedale\GridviewBundle\Tests\Support;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Test double satisfying EntityManagerInterface::getRepository() return type
 * while recording the params received by search().
 */
class RecordingRepository extends EntityRepository
{
    public ?array $receivedParams = null;

    public function __construct(private QueryBuilder $queryBuilderToReturn)
    {
    }

    public function search(array $params = []): QueryBuilder
    {
        $this->receivedParams = $params;

        return $this->queryBuilderToReturn;
    }
}

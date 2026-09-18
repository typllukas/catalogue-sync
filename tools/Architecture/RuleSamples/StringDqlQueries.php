<?php

declare(strict_types=1);

namespace Architecture\RuleSamples;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final class StringDqlQueries
{
    public function deleteEverythingWithADqlString(EntityManagerInterface $entityManager): void
    {
        $entityManager->createQuery('DELETE FROM AnyEntity anyEntity')->execute();
    }

    public function buildTheQueryInstead(EntityManagerInterface $entityManager): QueryBuilder
    {
        return $entityManager->createQueryBuilder();
    }

    public function createAQueryOnSomethingElse(ReportBuilder $reportBuilder): string
    {
        return $reportBuilder->createQuery('every product changed this week');
    }
}

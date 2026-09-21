<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

trait EntityGettersTrait
{
    /**
     * @param class-string<T> $entityClass
     *
     * @return T
     *
     * @template T of object
     */
    protected function getGenericEntity(Ulid $ulid, string $entityClass): object
    {
        $entity = self::getContainer()->get(EntityManagerInterface::class)->find($entityClass, $ulid);
        self::assertInstanceOf($entityClass, $entity);

        return $entity;
    }

    protected function getProductEntity(string $ulid): Product
    {
        return $this->getGenericEntity(new Ulid($ulid), Product::class);
    }
}

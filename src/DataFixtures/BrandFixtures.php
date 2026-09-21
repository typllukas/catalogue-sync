<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Brand;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Ulid;

final class BrandFixtures extends Fixture
{
    public const string BRAND_MAKITA_ULID = '01M36YYPDZY6WAA89C3CSEJW9M';

    public function load(ObjectManager $manager): void
    {
        $manager->persist($this->createBrandMakita());
        $manager->flush();
    }

    private function createBrandMakita(): Brand
    {
        $brand = new Brand(new Ulid(self::BRAND_MAKITA_ULID))->setName('Makita');
        $this->addReference(self::BRAND_MAKITA_ULID, $brand);

        return $brand;
    }
}

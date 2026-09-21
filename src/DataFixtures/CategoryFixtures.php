<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Ulid;

final class CategoryFixtures extends Fixture
{
    public const string CATEGORY_DRILLS_ULID = '01M36YYPHWFMEV8SXVZRPRCDGX';

    public function load(ObjectManager $manager): void
    {
        $manager->persist($this->createCategoryDrills());
        $manager->flush();
    }

    private function createCategoryDrills(): Category
    {
        $category = new Category(new Ulid(self::CATEGORY_DRILLS_ULID))
            ->setName('Vrtačky')
            ->setSlugPath('naradi/elektro/vrtacky');
        $this->addReference(self::CATEGORY_DRILLS_ULID, $category);

        return $category;
    }
}

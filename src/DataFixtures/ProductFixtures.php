<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Ulid;

final class ProductFixtures extends Fixture implements DependentFixtureInterface
{
    public const string PRODUCT_MAKITA_DRILL_ULID = '01M36YYPP44YHN4XF22CP16MRR';

    /**
     * @return list<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            BrandFixtures::class,
            CategoryFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $manager->persist($this->createProductMakitaDrill());
        $manager->flush();
    }

    private function createProductMakitaDrill(): Product
    {
        $product = new Product(new Ulid(self::PRODUCT_MAKITA_DRILL_ULID))
            ->setSku('MAK-DHP484Z')
            ->setName('Makita DHP484Z příklepový šroubovák')
            ->setDescription('Aku příklepový šroubovák bez baterie.')
            ->setEan('8590123456789')
            ->setPriceInMinorUnits(19990)
            ->setStockQuantity(5)
            ->setVisible(true)
            ->setBrand($this->getReference(BrandFixtures::BRAND_MAKITA_ULID, Brand::class))
            ->setCategory($this->getReference(CategoryFixtures::CATEGORY_DRILLS_ULID, Category::class));
        $this->addReference(self::PRODUCT_MAKITA_DRILL_ULID, $product);

        return $product;
    }
}

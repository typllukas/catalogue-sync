<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch\DocumentFactory;

use App\Elasticsearch\DocumentFactory\ProductDocumentFactory;
use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Enum\ProductSyncScope;
use LogicException;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * @see ProductDocumentFactory
 */
final class ProductDocumentFactoryTest extends TestCase
{
    private function buildProduct(int $price): Product
    {
        $brand = new Brand()->setName('Makita');
        $category = new Category()->setName('Vrtačky')->setSlugPath('naradi/elektro/vrtacky');

        $product = new Product()
            ->setSku('MAK-DHP484Z')
            ->setName('Makita DHP484Z příklepový šroubovák')
            ->setDescription('Aku příklepový šroubovák bez baterie.')
            ->setEan('8590123456789')
            ->setPriceInMinorUnits($price)
            ->setStockQuantity(12)
            ->setVisible(true)
            ->setBrand($brand)
            ->setCategory($category);
        $product->stampCreatedAt();
        $product->stampUpdatedAt();

        return $product;
    }

    public function testBuildsTheWholeDocumentWithThePriceInMajorUnits(): void
    {
        $product = $this->buildProduct(19990);

        $document = new ProductDocumentFactory()->build($product);

        self::assertSame(199.9, $document['price']);
        self::assertSame(
            ['naradi', 'naradi/elektro', 'naradi/elektro/vrtacky'],
            $document['category']['path'],
        );
        self::assertSame('Makita', $document['brand']);
        self::assertSame($product->getUpdatedAt()->format('Y-m-d\TH:i:s.uP'), $document['product_updated_at']);
    }

    public function testASubsetAlwaysCarriesProductUpdatedAt(): void
    {
        $product = $this->buildProduct(19990);

        $fields = new ProductDocumentFactory()->buildFields($product, [ProductSyncScope::PRICE]);

        self::assertSame(['product_updated_at', 'price'], array_keys($fields));
        self::assertSame(199.9, $fields['price']);
    }

    public function testEverythingIsNotAFieldSubset(): void
    {
        $this->expectException(LogicException::class);

        new ProductDocumentFactory()->buildFields($this->buildProduct(1), [ProductSyncScope::EVERYTHING]);
    }
}

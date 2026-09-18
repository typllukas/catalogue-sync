<?php

declare(strict_types=1);

namespace App\Elasticsearch\DocumentFactory;

use App\Entity\Product;
use App\Enum\ProductSyncScope;
use LogicException;

use function explode;

/**
 * A key not declared in ProductIndexDefinition is rejected, the mapping is dynamic: strict.
 *
 * @phpstan-type ProductDocument array{
 *     sku: string,
 *     name: string,
 *     description: string,
 *     brand: string,
 *     category: array{path: list<string>},
 *     price: float|int,
 *     stock_quantity: int,
 *     ean: string,
 *     visible: bool,
 *     product_updated_at: string,
 * }
 */
final class ProductDocumentFactory
{
    public const string PRODUCT_UPDATED_AT_FIELD = 'product_updated_at';

    public const int MINOR_UNITS_PER_WHOLE = 100;

    /**
     * @return ProductDocument
     */
    public function build(Product $product): array
    {
        return [
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'brand' => $product->getBrand()->getName(),
            'category' => ['path' => $this->buildCategoryPaths($product->getCategory()->getSlugPath())],
            'price' => $product->getPriceInMinorUnits() / self::MINOR_UNITS_PER_WHOLE,
            'stock_quantity' => $product->getStockQuantity(),
            'ean' => $product->getEan(),
            'visible' => $product->isVisible(),
            self::PRODUCT_UPDATED_AT_FIELD => $product->getUpdatedAt()->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * Every ancestor, not just the leaf: a term on 'naradi' then finds the whole subtree, and a terms
     * aggregation can count a level that holds no product of its own.
     *
     * @return list<string>
     */
    private function buildCategoryPaths(string $leafPath): array
    {
        $paths = [];
        $walked = '';
        foreach (explode('/', $leafPath) as $slug) {
            $walked = $walked === '' ? $slug : $walked . '/' . $slug;
            $paths[] = $walked;
        }

        return $paths;
    }

    /**
     * product_updated_at goes with every subset: a partial write that left it behind would report the
     * product as drifted for as long as nothing else touched it.
     *
     * @param list<ProductSyncScope> $scopes
     *
     * @return array<string, mixed>
     */
    public function buildFields(Product $product, array $scopes): array
    {
        $document = $this->build($product);
        $fields = [self::PRODUCT_UPDATED_AT_FIELD => $document[self::PRODUCT_UPDATED_AT_FIELD]];

        foreach ($scopes as $scope) {
            $fieldName = match ($scope) {
                ProductSyncScope::PRICE => 'price',
                ProductSyncScope::STOCK => 'stock_quantity',
                ProductSyncScope::EVERYTHING => throw new LogicException('EVERYTHING is sent as build().'),
            };
            $fields[$fieldName] = $document[$fieldName];
        }

        return $fields;
    }
}

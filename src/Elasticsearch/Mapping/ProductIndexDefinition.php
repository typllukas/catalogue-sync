<?php

declare(strict_types=1);

namespace App\Elasticsearch\Mapping;

use App\Elasticsearch\DocumentFactory\ProductDocumentFactory;
use App\Repository\DeletedProductRepository;
use App\Repository\ProductRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use Symfony\Component\Uid\Ulid;

use function array_merge;

/**
 * @phpstan-import-type ProductDocument from ProductDocumentFactory
 */
final readonly class ProductIndexDefinition implements IndexDefinitionInterface
{
    /**
     * Caps offset paging. The Elasticsearch default, declared so the index and
     * ProductSearchQueryFactory::build() agree.
     */
    public const int MAX_RESULT_WINDOW = 10000;

    private const int HYDRATION_BATCH_SIZE = 1000;

    public function __construct(
        private ProductRepository $productRepository,
        private DeletedProductRepository $deletedProductRepository,
        private ProductDocumentFactory $productDocumentFactory,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return array_merge(
            ['index' => ['max_result_window' => self::MAX_RESULT_WINDOW]],
            CzechAnalysis::SETTINGS,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getMapping(): array
    {
        return [
            'dynamic' => 'strict',
            'properties' => [
                'sku' => ['type' => 'keyword'],
                'name' => [
                    'type' => 'text',
                    'analyzer' => 'czech_folded_stems',
                    'fields' => [
                        // unstemmed: the stemmer turns sek into sk, a prefix of nothing indexed
                        'prefix' => [
                            'type' => 'text',
                            'analyzer' => 'folded_words',
                        ],
                    ],
                ],
                'description' => [
                    'type' => 'text',
                    'analyzer' => 'czech_folded_stems',
                ],
                'brand' => ['type' => 'keyword'],
                'category' => [
                    'properties' => [
                        'path' => ['type' => 'keyword'],
                    ],
                ],
                'price' => [
                    'type' => 'scaled_float',
                    'scaling_factor' => ProductDocumentFactory::MINOR_UNITS_PER_WHOLE,
                ],
                'stock_quantity' => ['type' => 'integer'],
                'ean' => ['type' => 'keyword'],
                'visible' => ['type' => 'boolean'],
                ProductDocumentFactory::PRODUCT_UPDATED_AT_FIELD => [
                    'type' => 'date',
                    'index' => false,
                ],
            ],
        ];
    }

    /**
     * @return Generator<string, ProductDocument>
     */
    public function iterateDocuments(): Generator
    {
        yield from $this->buildDocumentsFrom(
            $this->productRepository->iterateIdBatches(self::HYDRATION_BATCH_SIZE),
        );
    }

    /**
     * @return Generator<Ulid>
     */
    public function iterateIdsOfDocumentsDeletedSince(DateTimeImmutable $since): Generator
    {
        yield from $this->deletedProductRepository->iterateIdsOfProductsDeletedSince($since);
    }

    /**
     * @return Generator<string, ProductDocument>
     */
    public function iterateDocumentsChangedSince(DateTimeImmutable $since): Generator
    {
        yield from $this->buildDocumentsFrom(
            $this->productRepository->iterateIdBatchesForChangesSince($since, self::HYDRATION_BATCH_SIZE),
        );
    }

    /**
     * @param iterable<array<int, Ulid>> $idBatches
     *
     * @return Generator<string, ProductDocument>
     */
    private function buildDocumentsFrom(iterable $idBatches): Generator
    {
        foreach ($idBatches as $ids) {
            foreach ($this->productRepository->findForIndexing($ids) as $product) {
                yield $product->getId()->toBase32() => $this->productDocumentFactory->build($product);
            }

            $this->entityManager->clear();
        }
    }
}

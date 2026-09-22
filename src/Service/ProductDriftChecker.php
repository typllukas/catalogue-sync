<?php

declare(strict_types=1);

namespace App\Service;

use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\DocumentFactory\ProductDocumentFactory;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Elasticsearch\IndexNameFactory;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Generator;
use Symfony\Component\Uid\Ulid;

use function array_map;
use function count;
use function is_array;
use function ksort;

final readonly class ProductDriftChecker
{
    private const int COMPARISON_BATCH_SIZE = 1000;

    public function __construct(
        private Client $client,
        private ProductRepository $productRepository,
        private ProductDocumentFactory $productDocumentFactory,
        private EntityManagerInterface $entityManager,
        private IndexNameFactory $indexNameFactory,
    ) {
    }

    /**
     * @return Generator<string> base32 product ids
     *
     * @throws SearchUnavailableException
     */
    public function iterateDriftedProductIds(): Generator
    {
        foreach ($this->productRepository->iterateIdBatches(self::COMPARISON_BATCH_SIZE) as $productIds) {
            yield from $this->findDriftedAmong($productIds);

            // the batch hydrated a thousand products with their brand and category, as the reindex does
            $this->entityManager->clear();
        }
    }

    /**
     * A sample taken from what changed most recently, that is where drift is.
     *
     * @throws SearchUnavailableException
     */
    public function countDriftedInSample(int $limit): int
    {
        return count($this->findDriftedAmong($this->productRepository->findRecentlyChangedIds($limit)));
    }

    /**
     * @throws SearchUnavailableException
     */
    public function countSurplusDocuments(): int
    {
        try {
            $response = ResponseBody::read($this->client->count(['index' => $this->indexNameFactory->build()]));
        } catch (ElasticsearchException | TransportException $exception) {
            if ($exception instanceof ClientResponseException && $exception->getCode() === 404) {
                return 0;
            }

            throw new SearchUnavailableException('Elasticsearch is not answering.', previous: $exception);
        }

        return ResponseBody::readIntegerStrict($response, 'count') - $this->productRepository->count();
    }

    /**
     * @param list<Ulid> $productIds
     *
     * @return array<int, string>
     *
     * @throws SearchUnavailableException
     */
    private function findDriftedAmong(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $documentsById = $this->readDocuments($productIds);
        $driftedIds = [];

        foreach ($this->productRepository->findForIndexing($productIds) as $product) {
            $documentId = $product->getId()->toBase32();
            $document = $documentsById[$documentId] ?? null;

            if ($document === null || !$this->matches($this->productDocumentFactory->build($product), $document)) {
                $driftedIds[] = $documentId;
            }
        }

        return $driftedIds;
    }

    /**
     * Sorted first, because a partial update merges its fields in and the key order in _source stops
     * following the document factory.
     *
     * @param array<string, mixed> $rebuiltDocument
     * @param array<array-key, mixed> $document
     */
    private function matches(array $rebuiltDocument, array $document): bool
    {
        self::sortByKeyDeeply($rebuiltDocument);
        self::sortByKeyDeeply($document);

        return $rebuiltDocument === $document;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function sortByKeyDeeply(array &$values): void
    {
        ksort($values);

        foreach ($values as &$value) {
            if (is_array($value)) {
                self::sortByKeyDeeply($value);
            }
        }
    }

    /**
     * @param list<Ulid> $productIds
     *
     * @return array<string, array<array-key, mixed>>
     *
     * @throws SearchUnavailableException
     */
    private function readDocuments(array $productIds): array
    {
        $documentIds = array_map(static fn (Ulid $productId): string => $productId->toBase32(), $productIds);

        try {
            $response = ResponseBody::read($this->client->mget([
                'index' => $this->indexNameFactory->build(),
                'body' => ['ids' => $documentIds],
            ]));
        } catch (ElasticsearchException | TransportException $exception) {
            // no alias before the first reindex, so every row counts as drifted
            if ($exception instanceof ClientResponseException && $exception->getCode() === 404) {
                return [];
            }

            throw new SearchUnavailableException('Elasticsearch is not answering.', previous: $exception);
        }

        $documentsById = [];
        foreach (ResponseBody::readArray($response, 'docs') as $documentOrMiss) {
            $document = ResponseBody::narrowToArray($documentOrMiss);
            if (($document['found'] ?? null) !== true) {
                continue;
            }

            $documentsById[ResponseBody::readStringStrict($document, '_id')]
                = ResponseBody::readArray($document, '_source');
        }

        return $documentsById;
    }
}

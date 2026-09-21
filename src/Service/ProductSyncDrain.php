<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\DrainResult;
use App\DTO\PendingProductSync;
use App\Elasticsearch\Client\BulkResultCounts;
use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\DocumentFactory\ProductDocumentFactory;
use App\Elasticsearch\Exception\BulkIndexingFailedException;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Elasticsearch\IndexNameFactory;
use App\Entity\ProductSyncRun;
use App\Enum\ProductSyncOperation;
use App\Enum\ProductSyncScope;
use App\Helper\MixedToInteger;
use App\Repository\ProductRepository;
use App\Repository\ProductSyncOutboxRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Component\Uid\Ulid;

use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function hrtime;
use function in_array;
use function intdiv;
use function sprintf;

final readonly class ProductSyncDrain
{
    private const string DRAIN_LOCK_PREFIX = 'catalogue_sync_drain_';

    private const int RETRY_ON_CONFLICT = 3;

    private const int NANOSECONDS_PER_MILLISECOND = 1000000;

    public function __construct(
        private Client $client,
        private Connection $connection,
        private ProductSyncOutboxRepository $productSyncOutboxRepository,
        private ProductRepository $productRepository,
        private ProductDocumentFactory $productDocumentFactory,
        private EntityManagerInterface $entityManager,
        private IndexNameFactory $indexNameFactory,
    ) {
    }

    /**
     * @throws SearchUnavailableException|BulkIndexingFailedException
     */
    public function drain(int $batchSize): DrainResult
    {
        $startedAt = MixedToInteger::transformStrict(hrtime(true));

        /**
         * A supervisor tick landing on a slow batch would otherwise send every row of it twice. The lock is
         * server wide, so it is named per index, or the test suite would wait on the dashboard's drain.
         */
        $lockName = self::DRAIN_LOCK_PREFIX . $this->indexNameFactory->build();
        if ($this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]) !== 1) {
            return new DrainResult(0, 0, 0, 0, $this->measureElapsed($startedAt), 0);
        }

        try {
            return $this->drainOneBatch($batchSize, $startedAt);
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * @throws SearchUnavailableException|BulkIndexingFailedException
     */
    private function drainOneBatch(int $batchSize, int $startedAt): DrainResult
    {
        // a second drain in the same process would otherwise read products out of the identity map
        $this->entityManager->clear();
        $indexName = $this->indexNameFactory->build();
        $pendingProductSyncs = $this->productSyncOutboxRepository->findPending($indexName, $batchSize);

        if ($pendingProductSyncs === []) {
            return new DrainResult(0, 0, 0, 0, $this->measureElapsed($startedAt), 0);
        }

        $operations = array_merge(
            $this->buildDeleteOperations($indexName, $pendingProductSyncs),
            $this->buildWriteOperations($indexName, $pendingProductSyncs),
        );

        $counts = $this->sendBulk($operations);

        // release the rest, so one document the index always refuses does not block the queue
        $keptIds = array_merge($counts->conflictedIds, array_keys($counts->failures));
        $releasableProductSyncs = array_values(array_filter(
            $pendingProductSyncs,
            static fn (PendingProductSync $pendingProductSync): bool => !in_array(
                $pendingProductSync->productId->toBase32(),
                $keptIds,
                true,
            ),
        ));
        $releasedCount = $this->productSyncOutboxRepository->release($indexName, $releasableProductSyncs);

        $drainResult = new DrainResult(
            $counts->created + $counts->updated,
            $counts->noop,
            $counts->deleted,
            count($counts->conflictedIds),
            $this->measureElapsed($startedAt),
            count($pendingProductSyncs) - $releasedCount,
        );
        $this->recordRun($drainResult);

        $firstRefusedId = array_key_first($counts->failures);
        if ($firstRefusedId !== null) {
            throw new BulkIndexingFailedException(sprintf(
                'A drain of %d products: %d were refused and stay marked. First: %s said %s',
                count($pendingProductSyncs),
                count($counts->failures),
                $firstRefusedId,
                $counts->failures[$firstRefusedId],
            ));
        }

        return $drainResult;
    }

    /**
     * A partial update against a document the index does not hold is a 404, and doc_as_upsert with
     * a subset of fields would create a half built document that is searchable.
     *
     * @param array<int, PendingProductSync> $pendingProductSyncs
     *
     * @return list<array<string, mixed>>
     *
     * @throws SearchUnavailableException
     */
    private function buildWriteOperations(string $indexName, array $pendingProductSyncs): array
    {
        // array_values, because array_filter keeps the keys and a gap makes the id list encode as a JSON object
        $writes = array_values(array_filter(
            $pendingProductSyncs,
            static fn (PendingProductSync $pendingProductSync): bool => $pendingProductSync->operation
                === ProductSyncOperation::WRITE,
        ));
        if ($writes === []) {
            return [];
        }

        $productIds = array_map(
            static fn (PendingProductSync $pendingProductSync): Ulid => $pendingProductSync->productId,
            $writes,
        );
        $idsTheIndexHolds = $this->findIdsTheIndexHolds($indexName, $productIds);

        $productsById = [];
        foreach ($this->productRepository->findForIndexing($productIds) as $product) {
            $productsById[$product->getId()->toBase32()] = $product;
        }

        $operations = [];
        foreach ($writes as $write) {
            $documentId = $write->productId->toBase32();
            // withdrawn after the mark, and a later write may have replaced its delete row
            if (!array_key_exists($documentId, $productsById)) {
                $operations[] = ['delete' => ['_index' => $indexName, '_id' => $documentId]];
                continue;
            }

            $sendsWholeDocument = !in_array($documentId, $idsTheIndexHolds, true)
                || in_array(ProductSyncScope::EVERYTHING, $write->scopes, true);

            $operations[] = [
                'update' => [
                    '_index' => $indexName,
                    '_id' => $documentId,
                    'retry_on_conflict' => self::RETRY_ON_CONFLICT,
                ],
            ];
            $operations[] = $sendsWholeDocument
                ? [
                    'doc' => $this->productDocumentFactory->build($productsById[$documentId]),
                    'doc_as_upsert' => true,
                ]
                : [
                    'doc' => $this->productDocumentFactory->buildFields(
                        $productsById[$documentId],
                        $write->scopes,
                    ),
                ];
        }

        return $operations;
    }

    /**
     * @param array<int, PendingProductSync> $pendingProductSyncs
     *
     * @return list<array<string, mixed>>
     */
    private function buildDeleteOperations(string $indexName, array $pendingProductSyncs): array
    {
        $operations = [];
        foreach ($pendingProductSyncs as $pendingProductSync) {
            if ($pendingProductSync->operation !== ProductSyncOperation::DELETE) {
                continue;
            }

            $operations[] = [
                'delete' => [
                    '_index' => $indexName,
                    '_id' => $pendingProductSync->productId->toBase32(),
                ],
            ];
        }

        return $operations;
    }

    /**
     * @param list<Ulid> $productIds
     *
     * @return array<int, string>
     *
     * @throws SearchUnavailableException
     */
    private function findIdsTheIndexHolds(string $indexName, array $productIds): array
    {
        $documentIds = array_map(static fn (Ulid $productId): string => $productId->toBase32(), $productIds);

        try {
            $response = ResponseBody::read($this->client->mget([
                'index' => $indexName,
                'body' => ['ids' => $documentIds],
                '_source' => 'false',
            ]));
        } catch (ElasticsearchException | TransportException $exception) {
            throw new SearchUnavailableException('Elasticsearch is not answering.', previous: $exception);
        }

        $heldIds = [];
        foreach (ResponseBody::readArray($response, 'docs') as $documentOrMiss) {
            $document = ResponseBody::narrowToArray($documentOrMiss);
            // missing index: 200 with an error per document, and an upsert would create one that breaks the reindex
            if (array_key_exists('error', $document)) {
                $errorType = ResponseBody::readStringStrict(ResponseBody::narrowToArray($document['error']), 'type');

                throw new SearchUnavailableException($errorType === 'index_not_found_exception'
                    ? sprintf('Index %s does not exist, run the reindex first.', $indexName)
                    : sprintf('Elasticsearch could not read the documents: %s', $errorType));
            }

            if (($document['found'] ?? null) === true) {
                $heldIds[] = ResponseBody::readStringStrict($document, '_id');
            }
        }

        return $heldIds;
    }

    /**
     * detect_noop is the default on an update carrying a doc.
     *
     * @param list<array<string, mixed>> $operations
     *
     * @throws SearchUnavailableException
     */
    private function sendBulk(array $operations): BulkResultCounts
    {
        if ($operations === []) {
            return new BulkResultCounts(0, 0, 0, 0, [], []);
        }

        try {
            return ResponseBody::countBulkResults(
                ResponseBody::read($this->client->bulk(['body' => $operations])),
            );
        } catch (ElasticsearchException | TransportException $exception) {
            throw new SearchUnavailableException('Writing to the search index failed.', previous: $exception);
        }
    }

    private function recordRun(DrainResult $drainResult): void
    {
        $this->entityManager->persist(new ProductSyncRun(
            new Ulid(),
            new DateTimeImmutable(),
            $drainResult->writtenProductCount,
            $drainResult->unchangedProductCount,
            $drainResult->elapsedMilliseconds,
        ));
        $this->entityManager->flush();
    }

    /**
     * hrtime returns an int on every 64 bit build.
     */
    private function measureElapsed(int $startedAt): int
    {
        return intdiv(
            MixedToInteger::transformStrict(hrtime(true)) - $startedAt,
            self::NANOSECONDS_PER_MILLISECOND,
        );
    }
}

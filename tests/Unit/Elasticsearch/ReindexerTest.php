<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch;

use App\Elasticsearch\Exception\BulkIndexingFailedException;
use App\Elasticsearch\IndexNameFactory;
use App\Elasticsearch\Mapping\IndexDefinitionInterface;
use App\Elasticsearch\Reindexer;
use Closure;
use DateTimeImmutable;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Generator;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Uid\Ulid;

use function array_column;
use function array_filter;
use function array_map;
use function array_search;
use function array_values;
use function count;
use function explode;
use function json_encode;
use function sprintf;
use function str_contains;
use function strval;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * @see Reindexer
 */
final class ReindexerTest extends TestCase
{
    private const string ALIAS_PATH = '/_alias/products';

    /**
     * The wildcard is percent-encoded by the time it reaches the transport.
     */
    private const string VERSIONED_LISTING_PATH = '/products_v%2A';

    /** @var list<array{source: string, since: DateTimeImmutable, askedAt: DateTimeImmutable}> */
    private array $catchUpQuestions = [];

    /** @var list<string> */
    private array $progressMessages = [];

    /**
     * @param array<string, mixed> $body
     */
    private function buildJsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            [
                'Content-Type' => 'application/json',
                Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME,
            ],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<int, string> $versionedIndexNames
     * @param array<string, mixed> $aliasResponseBody
     *
     * @return Closure(RequestInterface): ResponseInterface
     */
    private function answerClusterWith(
        array $versionedIndexNames,
        int $aliasStatus,
        array $aliasResponseBody,
    ): Closure {
        return function (RequestInterface $request) use (
            $versionedIndexNames,
            $aliasStatus,
            $aliasResponseBody,
        ): ResponseInterface {
            $path = $request->getUri()->getPath();

            if ($path === self::ALIAS_PATH) {
                return $this->buildJsonResponse($aliasStatus, $aliasResponseBody);
            }

            if ($path === self::VERSIONED_LISTING_PATH) {
                $listing = [];
                foreach ($versionedIndexNames as $indexName) {
                    $listing[$indexName] = ['aliases' => []];
                }

                return $this->buildJsonResponse(200, $listing);
            }

            return $this->buildJsonResponse(200, []);
        };
    }

    /**
     * @return Closure(RequestInterface): ResponseInterface
     */
    private function answerClusterWithTheAliasOnV7(): Closure
    {
        return $this->answerClusterWith(['products_v7'], 200, ['products_v7' => ['aliases' => ['products' => []]]]);
    }

    /**
     * @param array<string, array<string, mixed>> $documents
     * @param array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>} $changesPerPass
     * @param array{0: list<Ulid>, 1: list<Ulid>} $deletionsPerPass
     */
    private function buildIndexDefinition(
        array $documents,
        array $changesPerPass,
        array $deletionsPerPass,
    ): IndexDefinitionInterface {
        $indexDefinition = self::createStub(IndexDefinitionInterface::class);
        $indexDefinition->method('getSettings')->willReturn([]);
        $indexDefinition->method('getMapping')->willReturn([]);
        $indexDefinition->method('iterateDocuments')
            ->willReturnCallback(static function () use ($documents): Generator {
                yield from $documents;
            });

        // each pass asks again, and a generator cannot be traversed twice, so one per call
        $changePass = 0;
        $indexDefinition->method('iterateDocumentsChangedSince')
            ->willReturnCallback(function (DateTimeImmutable $since) use ($changesPerPass, &$changePass): Generator {
                $this->recordCatchUpQuestion('changes', $since);

                yield from $changesPerPass[$changePass++];
            });

        $deletionPass = 0;
        $indexDefinition->method('iterateIdsOfDocumentsDeletedSince')
            ->willReturnCallback(function (DateTimeImmutable $since) use (
                $deletionsPerPass,
                &$deletionPass,
            ): Generator {
                $this->recordCatchUpQuestion('deletions', $since);

                yield from $deletionsPerPass[$deletionPass++];
            });

        return $indexDefinition;
    }

    private function recordCatchUpQuestion(string $source, DateTimeImmutable $since): void
    {
        $this->catchUpQuestions[] = [
            'source' => $source,
            'since' => $since,
            'askedAt' => new DateTimeImmutable(),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $documents
     * @param Closure(RequestInterface): ResponseInterface $answerRequest
     *
     * @return list<array{method: string, path: string, body: string}>
     */
    private function recordReindexRequests(array $documents, Closure $answerRequest): array
    {
        return $this->runReindex($this->buildIndexDefinition($documents, [[], []], [[], []]), $answerRequest);
    }

    /**
     * @param array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>} $changesPerPass
     * @param array{0: list<Ulid>, 1: list<Ulid>} $deletionsPerPass
     * @param Closure(RequestInterface): ResponseInterface $answerRequest
     *
     * @return list<array{method: string, path: string, body: string}>
     */
    private function recordCatchUpOnlyReindexRequests(
        array $changesPerPass,
        array $deletionsPerPass,
        Closure $answerRequest,
    ): array {
        return $this->runReindex(
            $this->buildIndexDefinition([], $changesPerPass, $deletionsPerPass),
            $answerRequest,
        );
    }

    /**
     * @param Closure(RequestInterface): ResponseInterface $answerRequest
     *
     * @return list<array{method: string, path: string, body: string}>
     */
    private function runReindex(IndexDefinitionInterface $indexDefinition, Closure $answerRequest): array
    {
        $requests = [];

        $recordAndAnswerRequest = static function (RequestInterface $request) use (
            &$requests,
            $answerRequest,
        ): ResponseInterface {
            $requests[] = [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'body' => strval($request->getBody()),
            ];

            return $answerRequest($request);
        };

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback($recordAndAnswerRequest);

        new Reindexer(ClientBuilder::create()->setHttpClient($httpClient)->build(), new IndexNameFactory(''), 1)
            ->reindex($indexDefinition, function (string $message): void {
                $this->progressMessages[] = $message;
            });

        return $requests;
    }

    /**
     * @param list<array{method: string, path: string, body: string}> $requests
     *
     * @return list<array{method: string, path: string, body: string}>
     */
    private function filterRequestsByMethod(array $requests, string $method): array
    {
        return array_values(
            array_filter($requests, static fn (array $request): bool => $request['method'] === $method),
        );
    }

    /**
     * @param list<array{method: string, path: string, body: string}> $requests
     *
     * @return list<array{method: string, path: string, body: string}>
     */
    private function filterRequestsByPath(array $requests, string $path): array
    {
        return array_values(
            array_filter($requests, static fn (array $request): bool => $request['path'] === $path),
        );
    }

    /**
     * @param list<array{method: string, path: string, body: string}> $requests
     *
     * @return array<int, int> the lines each bulk request carried
     */
    private function countBulkOperations(array $requests): array
    {
        $operationCounts = [];
        foreach ($requests as $request) {
            if ($request['path'] !== '/_bulk') {
                continue;
            }

            $operationCounts[] = count(explode("\n", trim($request['body'])));
        }

        return $operationCounts;
    }

    public function testTheIndexIsRefreshedBeforeTheAliasMovesOntoIt(): void
    {
        $requests = $this->recordReindexRequests([], $this->answerClusterWithTheAliasOnV7());

        $paths = array_column($requests, 'path');
        $refreshPosition = array_search('/products_v8/_refresh', $paths, true);
        $aliasPosition = array_search('/_aliases', $paths, true);

        self::assertIsInt($refreshPosition);
        self::assertIsInt($aliasPosition);
        self::assertLessThan($aliasPosition, $refreshPosition);
    }

    /**
     * One document over the batch size, so the remainder cannot hide inside the full batch.
     */
    public function testDocumentsAreSentOneBatchAtATimeAndTheRemainderGetsItsOwn(): void
    {
        $documents = [];
        for ($documentIndex = 0; $documentIndex <= Reindexer::BULK_BATCH_SIZE; ++$documentIndex) {
            $documents[new Ulid()->toBase32()] = ['sku' => sprintf('MAK-%d', $documentIndex)];
        }

        $requests = $this->recordReindexRequests($documents, $this->answerClusterWithTheAliasOnV7());

        // an index operation is two lines, the action and the document
        self::assertSame([Reindexer::BULK_BATCH_SIZE * 2, 2], $this->countBulkOperations($requests));
        self::assertContains(sprintf('documents indexed: %d', Reindexer::BULK_BATCH_SIZE), $this->progressMessages);
        self::assertContains(sprintf('documents indexed: %d', Reindexer::BULK_BATCH_SIZE + 1), $this->progressMessages);
    }

    public function testDeletionsAreSentOneBatchAtATimeAndTheRemainderGetsItsOwn(): void
    {
        $deletedIds = [];
        for ($deletionIndex = 0; $deletionIndex <= Reindexer::BULK_BATCH_SIZE; ++$deletionIndex) {
            $deletedIds[] = new Ulid();
        }

        $requests = $this->recordCatchUpOnlyReindexRequests(
            [[], []],
            [$deletedIds, []],
            $this->answerClusterWithTheAliasOnV7(),
        );

        self::assertSame([Reindexer::BULK_BATCH_SIZE, 1], $this->countBulkOperations($requests));
    }

    public function testTheNewIndexIsNumberedFromTheVersionedIndicesAndNotFromTheAlias(): void
    {
        $requests = $this->recordReindexRequests([], $this->answerClusterWith(
            ['products_v7', 'products_v8'],
            200,
            ['products_v7' => ['aliases' => ['products' => []]]],
        ));

        self::assertContains('/products_v9', array_column($requests, 'path'));
        $createdIndexPaths = array_column($this->filterRequestsByMethod($requests, 'PUT'), 'path');
        self::assertNotContains('/products_v8', $createdIndexPaths);
    }

    public function testTheAliasIsMovedInOneCallSoItNeverPointsNowhere(): void
    {
        $requests = $this->recordReindexRequests([], $this->answerClusterWithTheAliasOnV7());

        $aliasUpdates = $this->filterRequestsByPath($requests, '/_aliases');

        self::assertCount(1, $aliasUpdates);
        self::assertJsonStringEqualsJsonString(
            '{"actions":['
                . '{"remove":{"index":"products_v7","alias":"products"}},'
                . '{"add":{"index":"products_v8","alias":"products"}}'
                . ']}',
            $aliasUpdates[0]['body'],
        );
    }

    public function testTheFirstReindexAddsTheAliasWithoutRemovingAnything(): void
    {
        $requests = $this->recordReindexRequests([], $this->answerClusterWith([], 404, [
            'error' => ['type' => 'alias_not_found_exception'],
            'status' => 404,
        ]));

        $aliasUpdates = $this->filterRequestsByPath($requests, '/_aliases');

        self::assertCount(1, $aliasUpdates);
        self::assertJsonStringEqualsJsonString(
            '{"actions":[{"add":{"index":"products_v1","alias":"products"}}]}',
            $aliasUpdates[0]['body'],
        );
    }

    public function testTheSupersededIndexSurvivesAndOlderOnesAreDropped(): void
    {
        $requests = $this->recordReindexRequests([], $this->answerClusterWith(
            ['products_v3', 'products_v7', 'products_v8'],
            200,
            ['products_v7' => ['aliases' => ['products' => []]]],
        ));

        self::assertSame(
            ['/products_v3', '/products_v8'],
            array_column($this->filterRequestsByMethod($requests, 'DELETE'), 'path'),
        );
    }

    public function testTheIndexIsFilledWithRefreshOffAndTunedBackBeforeTheSwitch(): void
    {
        $requests = $this->recordReindexRequests([], $this->answerClusterWithTheAliasOnV7());

        $settingsUpdates = array_values(
            array_filter($requests, static fn (array $request): bool => str_contains($request['path'], '_settings')),
        );

        self::assertCount(2, $settingsUpdates);
        self::assertJsonStringEqualsJsonString(
            '{"index":{"refresh_interval":-1,"number_of_replicas":0}}',
            $settingsUpdates[0]['body'],
        );
        self::assertJsonStringEqualsJsonString(
            '{"index":{"refresh_interval":null,"number_of_replicas":1}}',
            $settingsUpdates[1]['body'],
        );
    }

    public function testABulkThatReportsItemErrorsStopsTheRunBeforeTheSwitch(): void
    {
        $this->expectException(BulkIndexingFailedException::class);

        $this->recordReindexRequests(
            ['01M1CEXXTK566P0E1WFXK6FSPD' => ['sku' => 'MAK-1']],
            function (RequestInterface $request): ResponseInterface {
                if ($request->getUri()->getPath() === '/_bulk') {
                    return $this->buildJsonResponse(200, [
                        'errors' => true,
                        'items' => [
                            [
                                'index' => [
                                    '_id' => '01M1CEXXTK566P0E1WFXK6FSPD',
                                    'error' => ['type' => 'strict_dynamic_mapping_exception'],
                                ],
                            ],
                        ],
                    ]);
                }

                return $this->answerClusterWithTheAliasOnV7()($request);
            },
        );
    }

    public function testTheDeletionCatchUpRemovesTombstonedDocumentsFromTheNewIndex(): void
    {
        $beforeSwitch = new Ulid();
        $afterSwitch = new Ulid();

        $requests = $this->recordCatchUpOnlyReindexRequests(
            [[], []],
            [[$beforeSwitch], [$afterSwitch]],
            function (RequestInterface $request): ResponseInterface {
                if ($request->getUri()->getPath() === '/_bulk') {
                    return $this->buildJsonResponse(200, [
                        'errors' => false,
                        'items' => [['delete' => ['status' => 200, 'result' => 'deleted']]],
                    ]);
                }

                return $this->answerClusterWithTheAliasOnV7()($request);
            },
        );

        $bulkBodies = array_map(
            static fn (array $request): string => $request['body'],
            $this->filterRequestsByPath($requests, '/_bulk'),
        );

        self::assertCount(2, $bulkBodies);
        self::assertJsonStringEqualsJsonString(
            '{"delete":{"_index":"products_v8","_id":"' . $beforeSwitch->toBase32() . '"}}',
            $bulkBodies[0],
        );
        self::assertJsonStringEqualsJsonString(
            '{"delete":{"_index":"products_v8","_id":"' . $afterSwitch->toBase32() . '"}}',
            $bulkBodies[1],
        );
        self::assertContains(
            'Done. documents: 0, before the switch caught up changes: 0, deletions: 1, '
                . 'after it changes: 0, deletions: 1',
            $this->progressMessages,
        );
    }

    public function testATombstoneTheNewIndexNeverHeldIsNeitherAFailureNorADeletion(): void
    {
        $this->recordCatchUpOnlyReindexRequests(
            [[], []],
            [[new Ulid()], []],
            function (RequestInterface $request): ResponseInterface {
                if ($request->getUri()->getPath() === '/_bulk') {
                    return $this->buildJsonResponse(200, [
                        'errors' => false,
                        'items' => [['delete' => ['status' => 404, 'result' => 'not_found']]],
                    ]);
                }

                return $this->answerClusterWithTheAliasOnV7()($request);
            },
        );

        self::assertContains(
            'Done. documents: 0, before the switch caught up changes: 0, deletions: 0, '
                . 'after it changes: 0, deletions: 0',
            $this->progressMessages,
        );
    }

    /**
     * A tombstone left in the index serves a deleted product as a search hit.
     */
    public function testADeletionBulkThatReportsItemErrorsStopsTheRun(): void
    {
        $this->expectException(BulkIndexingFailedException::class);

        $this->recordCatchUpOnlyReindexRequests(
            [[], []],
            [[new Ulid()], []],
            function (RequestInterface $request): ResponseInterface {
                if ($request->getUri()->getPath() === '/_bulk') {
                    return $this->buildJsonResponse(200, [
                        'errors' => true,
                        'items' => [
                            [
                                'delete' => [
                                    '_id' => 'x',
                                    'status' => 404,
                                    'error' => ['type' => 'index_not_found_exception'],
                                ],
                            ],
                        ],
                    ]);
                }

                return $this->answerClusterWithTheAliasOnV7()($request);
            },
        );
    }

    public function testTheSecondPassAsksFromBeforeTheFirstOneRanSoNothingWrittenDuringItIsLost(): void
    {
        $this->recordCatchUpOnlyReindexRequests(
            [[], []],
            [[], []],
            $this->answerClusterWithTheAliasOnV7(),
        );

        self::assertSame(
            ['changes', 'deletions', 'changes', 'deletions'],
            array_column($this->catchUpQuestions, 'source'),
        );

        [$firstChanges, $firstDeletions, $secondChanges, $secondDeletions] = $this->catchUpQuestions;

        self::assertEquals($firstChanges['since'], $firstDeletions['since']);
        self::assertEquals($secondChanges['since'], $secondDeletions['since']);
        self::assertGreaterThan($firstChanges['since'], $secondChanges['since']);
        self::assertLessThanOrEqual($firstChanges['askedAt'], $secondChanges['since']);
    }
}
